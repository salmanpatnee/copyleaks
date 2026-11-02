<?php

use App\Enums\ScanStatus;
use App\Jobs\StartExportJob;
use App\Models\Scan;
use App\Models\ScanArtifact;
use App\Models\ScanEvent;
use App\Models\Submission;
use App\Services\Copyleaks\FakeScanClient;
use App\Services\Copyleaks\RawFileWriter;
use App\Services\Copyleaks\WebhookToken;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** Copyleaks is set up, on the sandbox, with an https webhook address. */
function copyleaksReady(array $overrides = []): void
{
    config([
        'copyleaks.email' => 'staff@example.test',
        'copyleaks.api_key' => 'test-api-key-not-real',
        'copyleaks.sandbox' => true,
        'copyleaks.webhook_url' => 'https://tool.example.test',
        'copyleaks.webhook_secret' => 'test-webhook-secret-not-real',
        ...$overrides,
    ]);
}

function fakeScanClient(): FakeScanClient
{
    return app(FakeScanClient::class);
}

/** A saved PDF submission with a real file on the fake private disk. */
function pdfSubmission(array $attributes = []): Submission
{
    $submission = Submission::factory()->create($attributes);
    Storage::disk('local')->put($submission->file_path, "%PDF-1.4 fake manuscript bytes\n");

    return $submission;
}

/** A scan whose webhook token hash really matches the derived token. */
function scanFor(Submission $submission, ScanStatus $status = ScanStatus::Waiting, array $attributes = []): Scan
{
    $scanId = strtolower((string) Str::ulid());

    $scan = Scan::factory()->create([
        'submission_id' => $submission->id,
        'copyleaks_scan_id' => $scanId,
        'webhook_token_hash' => app(WebhookToken::class)->hashFor($scanId),
        'status' => $status,
        'submitted_at' => $status === ScanStatus::Submitting ? null : now(),
        ...$attributes,
    ]);

    return $scan->refresh();
}

function webhookPath(Scan $scan, string $status = 'completed', ?string $token = null): string
{
    $token ??= app(WebhookToken::class)->for($scan->copyleaks_scan_id);

    return "/webhooks/copyleaks/{$scan->copyleaks_scan_id}/{$token}/{$status}";
}

/** @return array<string, mixed> */
function completedBody(Scan $scan, array $overrides = []): array
{
    return [
        'status' => 0,
        'developerPayload' => $scan->copyleaks_scan_id,
        'scannedDocument' => ['scanId' => $scan->copyleaks_scan_id, 'totalWords' => 120, 'credits' => 1],
        'results' => ['score' => ['aggregatedScore' => 12.5, 'identicalWords' => 10]],
        'notifications' => ['alerts' => [['code' => 'suspected-character-replacement']]],
        'downloadableReport' => ['status' => 'x'],
        ...$overrides,
    ];
}

/** @return array<string, mixed> */
function errorBody(Scan $scan): array
{
    return [
        'status' => 4,
        'developerPayload' => $scan->copyleaks_scan_id,
        'error' => ['type' => 'api_error', 'id' => 'bad', 'code' => 7, 'message' => 'Something broke'],
    ];
}

/** Posts raw JSON the way Copyleaks would, with the shared secret header unless told otherwise. */
function callWebhook(object $test, string $path, array|string $body, array $headers = []): TestResponse
{
    $raw = is_string($body) ? $body : json_encode($body);

    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_WEBHOOK_SECRET' => 'test-webhook-secret-not-real',
        ...$headers,
    ];

    return $test->call('POST', $path, [], [], [], $server, $raw);
}

/** A `completed` summary that lists the given result IDs, as Copyleaks sends it. */
function exportSummaryBody(Scan $scan, array $resultIds = ['res-a', 'res-b'], array $overrides = []): array
{
    return completedBody($scan, [
        'results' => [
            'score' => ['aggregatedScore' => 12.456, 'identicalWords' => 10],
            'internet' => array_map(fn (string $id): array => ['id' => $id, 'title' => 'x'], $resultIds),
            'database' => [],
            'batch' => [],
            'repositories' => [],
        ],
        'notifications' => ['alerts' => [[
            'code' => 'suspected-ai-text',
            'additionalData' => json_encode(['summary' => ['human' => 0.75, 'ai' => 0.25]]),
        ]]],
        ...$overrides,
    ]);
}

/**
 * A `fetching` scan with its summary file (`$tweakSummary` may change the summary body first) and artifact row saved, like spec 0004 leaves it.
 * Fires no event, so no export is queued.
 */
function fetchingScan(array $resultIds = ['res-a', 'res-b'], array $scanAttributes = [], ?callable $tweakSummary = null): Scan
{
    $scan = scanFor(pdfSubmission(), ScanStatus::Fetching, $scanAttributes);
    $summary = exportSummaryBody($scan, $resultIds);
    $raw = json_encode($tweakSummary === null ? $summary : $tweakSummary($summary));
    $file = app(RawFileWriter::class)->store("scans/{$scan->copyleaks_scan_id}/summary.json.gz", $raw);

    ScanArtifact::query()->insert([
        'scan_id' => $scan->id, 'kind' => 'summary', 'source_id' => '', 'path' => $file['path'],
        'sha256' => $file['sha256'], 'size_bytes' => $file['size'], 'created_at' => now(),
    ]);
    ScanEvent::query()->insert([
        'scan_id' => $scan->id, 'kind' => 'status', 'status' => 'completed', 'payload' => '{}',
        'received_at' => now(), 'handled_at' => now(),
    ]);

    return $scan->refresh();
}

/** Runs the export job for one numbered try, now, even while the queue is faked. */
function runExport(Scan $scan, int $attempt = 1): void
{
    app()->call([new StartExportJob($scan->id, $attempt), 'handle']);
}

function exportId(Scan $scan, int $attempt = 1): string
{
    return "{$scan->copyleaks_scan_id}-{$attempt}";
}

function exportPath(Scan $scan, string $exportId, string $part, ?string $resultId = null, ?string $token = null): string
{
    $token ??= app(WebhookToken::class)->for($scan->copyleaks_scan_id);
    $tail = $part === 'result' ? "result/{$resultId}" : $part;

    return "/webhooks/copyleaks/{$scan->copyleaks_scan_id}/{$token}/export/{$exportId}/{$tail}";
}

/** @return array<string, mixed> */
function exportCompletedBody(Scan $scan, array $tasks = [['isHealthy' => true, 'httpStatusCode' => 200, 'endpoint' => 'https://secret.example/token']]): array
{
    return ['completed' => true, 'developerPayload' => $scan->copyleaks_scan_id, 'tasks' => $tasks];
}

/** Pushes every file of an export and then the completion call, as a healthy Copyleaks would. */
function pushEverything(object $test, Scan $scan, int $attempt = 1, array $resultIds = ['res-a', 'res-b']): void
{
    $id = exportId($scan, $attempt);

    foreach ($resultIds as $resultId) {
        callWebhook($test, exportPath($scan, $id, 'result', $resultId), json_encode(['result' => $resultId]))->assertOk();
    }

    callWebhook($test, exportPath($scan, $id, 'text'), json_encode(['text' => 'crawled']))->assertOk();
    callWebhook($test, exportPath($scan, $id, 'completed'), exportCompletedBody($scan))->assertOk();
}
