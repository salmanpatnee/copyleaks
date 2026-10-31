<?php

use App\Enums\ScanStatus;
use App\Models\Scan;
use App\Models\Submission;
use App\Services\Copyleaks\FakeScanClient;
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
