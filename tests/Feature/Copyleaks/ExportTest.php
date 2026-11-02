<?php

use App\Actions\AdvanceExport;
use App\Actions\FinishScan;
use App\Enums\ArtifactKind;
use App\Enums\EventKind;
use App\Enums\ScanStatus;
use App\Jobs\DeleteRemoteScanJob;
use App\Jobs\StartExportJob;
use App\Models\Scan;
use App\Models\ScanArtifact;
use App\Models\ScanEvent;
use App\Services\Copyleaks\ExportResult;
use App\Services\Copyleaks\ScanClientUnavailable;
use App\Services\Copyleaks\WebhookToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once __DIR__.'/../../Support/CopyleaksTestHelpers.php';

beforeEach(function () {
    Storage::fake('local');
    copyleaksReady();
    RateLimiter::clear('copyleaks-export');
    Queue::fake([DeleteRemoteScanJob::class]);
});

function claimOf(Scan $scan, int $attempt = 1): ?ScanEvent
{
    return ScanEvent::query()->where('scan_id', $scan->id)->where('kind', EventKind::ExportStarted->value)
        ->where('status', exportId($scan, $attempt))->first();
}

describe('the export call', function () {
    it('is queued once when the summary arrives, with all result ids and no PDF report (AC-1)', function () {
        Queue::fake();
        $scan = scanFor(pdfSubmission(), ScanStatus::Waiting);

        callWebhook($this, webhookPath($scan), json_encode(exportSummaryBody($scan)))->assertOk();

        Queue::assertPushed(StartExportJob::class, 1);
        Queue::assertPushed(StartExportJob::class, fn (StartExportJob $job) => $job->scanId === $scan->id && $job->attempt === 1);
    });

    it('claims the try, sends one export call, and marks it accepted on 204 (AC-1)', function () {
        $scan = fetchingScan();

        runExport($scan);

        $client = fakeScanClient();
        expect($client->exported)->toHaveCount(1);
        $request = $client->exported[0];
        expect($request->exportId)->toBe(exportId($scan))
            ->and(array_keys($request->resultEndpoints))->toBe(['res-a', 'res-b'])
            ->and($request->resultEndpoints['res-a'])->toContain('/export/'.exportId($scan).'/result/res-a')
            ->and($request->textEndpoint)->toEndWith('/export/'.exportId($scan).'/text')
            ->and($request->completionEndpoint)->toEndWith('/completed')
            ->and($request->secret)->toBe('test-webhook-secret-not-real');

        $claim = claimOf($scan);
        expect($claim->handled_at)->not->toBeNull()
            ->and($claim->payload)->toBe(['attempt' => 1, 'expected_result_ids' => ['res-a', 'res-b']])
            ->and($scan->refresh()->copyleaks_export_id)->toBe(exportId($scan));
    });

    it('does nothing for an accepted try, and treats 409 as started', function () {
        $scan = fetchingScan();
        fakeScanClient()->willExport(ExportResult::conflict());

        runExport($scan);
        runExport($scan);

        expect(fakeScanClient()->exported)->toHaveCount(1)->and(claimOf($scan)->handled_at)->not->toBeNull();
    });

    it('reaches Copyleaks again on the same export id after a timeout, without using up a try (AC-1, AC-11)', function () {
        $scan = fetchingScan();
        fakeScanClient()->willExport(new ScanClientUnavailable('timeout'));

        runExport($scan);

        expect(claimOf($scan)->handled_at)->toBeNull()->and($scan->refresh()->status)->toBe(ScanStatus::Fetching);

        runExport($scan);

        expect(fakeScanClient()->exported)->toHaveCount(2)
            ->and(fakeScanClient()->exported[1]->exportId)->toBe(exportId($scan))
            ->and(ScanEvent::where('kind', EventKind::ExportStarted->value)->count())->toBe(1)
            ->and(claimOf($scan)->handled_at)->not->toBeNull();
    });

    it('fails the scan with a fixed reason when Copyleaks says 404 (AC-11)', function () {
        $scan = fetchingScan();
        fakeScanClient()->willExport(ExportResult::notFound());

        runExport($scan);

        expect($scan->refresh()->status)->toBe(ScanStatus::Failed)->and($scan->failure_reason)->toBe('Copyleaks no longer has this scan.');
    });

    it('counts another 4xx as an accepted try that failed and starts the next one (AC-11)', function () {
        Queue::fake();
        $scan = fetchingScan();
        fakeScanClient()->willExport(ExportResult::refused('400'));

        runExport($scan);

        expect($scan->refresh()->status)->toBe(ScanStatus::Fetching)->and(claimOf($scan)->handled_at)->not->toBeNull();
        Queue::assertPushed(StartExportJob::class, fn (StartExportJob $job) => $job->attempt === 2);
    });

    it('does not run for a scan that is not fetching, or past the third try', function () {
        $done = scanFor(pdfSubmission(), ScanStatus::Completed);
        app()->call([new StartExportJob($done->id, 1), 'handle']);
        $scan = fetchingScan();
        runExport($scan, 4);

        expect(fakeScanClient()->exported)->toBeEmpty();
    });
});

describe('the pushes', function () {
    beforeEach(function () {
        $this->scan = fetchingScan();
        runExport($this->scan);
        $this->id = exportId($this->scan);
    });

    it('saves a result byte for byte with its checksum and answers 200 (AC-3, AC-4)', function () {
        $raw = '{"result":  {"a":"é","b":[1, 2]},"text":"  spaced  "}';

        callWebhook($this, exportPath($this->scan, $this->id, 'result', 'res-a'), $raw)->assertOk()->assertContent('');

        $artifact = ScanArtifact::where('kind', ArtifactKind::Result->value)->sole();
        $stored = Storage::disk('local')->get("scans/{$this->scan->copyleaks_scan_id}/result-res-a.json.gz");
        expect($artifact->source_id)->toBe('res-a')
            ->and($artifact->path)->toBe("scans/{$this->scan->copyleaks_scan_id}/result-res-a.json.gz")
            ->and(gzdecode($stored))->toBe($raw)
            ->and($artifact->sha256)->toBe(hash('sha256', $stored))
            ->and($artifact->size_bytes)->toBe(strlen($stored));
    });

    it('saves the crawled text with an empty source id (AC-3)', function () {
        callWebhook($this, exportPath($this->scan, $this->id, 'text'), '{"text":"hello"}')->assertOk();

        $artifact = ScanArtifact::where('kind', ArtifactKind::Text->value)->sole();
        expect($artifact->source_id)->toBe('')->and($artifact->path)->toEndWith('/text.json.gz');
    });

    it('leaves one row and one file for a repeat, and rewrites a missing file only (AC-4)', function () {
        $path = exportPath($this->scan, $this->id, 'text');
        callWebhook($this, $path, '{"v":1}')->assertOk();
        callWebhook($this, $path, '{"v":2}')->assertOk();

        $file = "scans/{$this->scan->copyleaks_scan_id}/text.json.gz";
        expect(ScanArtifact::where('kind', 'text')->count())->toBe(1)->and(gzdecode(Storage::disk('local')->get($file)))->toBe('{"v":1}');

        Storage::disk('local')->delete($file);
        callWebhook($this, $path, '{"v":1}')->assertOk();

        expect(Storage::disk('local')->exists($file))->toBeTrue()->and(ScanArtifact::where('kind', 'text')->count())->toBe(1);
    });

    it('answers the empty 404 for every failed check and saves nothing (AC-2, AC-14)', function (callable $call) {
        $response = $call($this);

        $response->assertNotFound()->assertContent('');
        expect(ScanArtifact::where('kind', '!=', 'summary')->count())->toBe(0);
    })->with([
        'wrong token' => [fn ($t) => callWebhook($t, exportPath($t->scan, $t->id, 'text', null, str_repeat('a', 64)), '{}')],
        'wrong secret' => [fn ($t) => callWebhook($t, exportPath($t->scan, $t->id, 'text'), '{}', ['HTTP_X_WEBHOOK_SECRET' => 'nope'])],
        'unknown export id' => [fn ($t) => callWebhook($t, exportPath($t->scan, 'unknown-9', 'text'), '{}')],
        'unknown result id' => [fn ($t) => callWebhook($t, exportPath($t->scan, $t->id, 'result', 'other'), '{}')],
        'result id with odd characters' => [fn ($t) => callWebhook($t, exportPath($t->scan, $t->id, 'result', 'res.a'), '{}')],
        'invalid json' => [fn ($t) => callWebhook($t, exportPath($t->scan, $t->id, 'text'), '{not json')],
        'unknown scan' => [fn ($t) => callWebhook($t, '/webhooks/copyleaks/nope/'.str_repeat('a', 64).'/export/x/text', '{}')],
    ]);

    it('answers 413 over the cap, by header or by real length (AC-2)', function () {
        config(['copyleaks.max_export_bytes' => 100]);
        $path = exportPath($this->scan, $this->id, 'text');

        callWebhook($this, $path, '{}', ['CONTENT_LENGTH' => 101])->assertStatus(413);
        callWebhook($this, $path, json_encode(['x' => str_repeat('a', 200)]))->assertStatus(413);

        expect(ScanArtifact::where('kind', 'text')->count())->toBe(0);
    });

    it('answers 200 and saves nothing once the scan is no longer fetching (AC-5)', function () {
        Scan::whereKey($this->scan->id)->update(['status' => 'failed']);

        callWebhook($this, exportPath($this->scan, $this->id, 'text'), '{"v":1}')->assertOk();

        expect(ScanArtifact::where('kind', 'text')->count())->toBe(0);
    });

    it('accepts a push of an earlier export id of the same scan', function () {
        runExport($this->scan->refresh(), 2);

        callWebhook($this, exportPath($this->scan, $this->id, 'text'), '{"v":1}')->assertOk();

        expect(ScanArtifact::where('kind', 'text')->count())->toBe(1);
    });

    it('never writes a push body, token or secret to the log (AC-14)', function () {
        Log::spy();

        pushEverything($this, $this->scan);

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context = []) {
            $flat = json_encode($context);

            return ! str_contains($flat, 'crawled') && ! str_contains($flat, 'test-webhook-secret-not-real')
                && ! str_contains($flat, app(WebhookToken::class)->for($context['scan_id'] ?? ''));
        });
    });
});

describe('finishing', function () {
    it('completes a scan from fake pushes with both scores, and queues one delete (AC-6, AC-7, AC-8, AC-12)', function () {
        $scan = fetchingScan();
        runExport($scan);

        pushEverything($this, $scan);

        $scan->refresh();
        expect($scan->status)->toBe(ScanStatus::Completed)
            ->and($scan->similarity_percent)->toBe('12.46')
            ->and($scan->ai_percent)->toBe('25.00')
            ->and($scan->completed_at)->not->toBeNull();
        Queue::assertPushed(DeleteRemoteScanJob::class, 1);

        $event = ScanEvent::where('kind', EventKind::ExportCompleted->value)->sole();
        expect($event->status)->toBe(exportId($scan))
            ->and($event->payload)->toBe(['completed' => true, 'tasks' => [['isHealthy' => true, 'httpStatusCode' => 200]]])
            ->and(json_encode($event->payload))->not->toContain('secret.example');
    });

    it('runs from the real webhook through the listener to a finished scan', function () {
        Queue::fake([DeleteRemoteScanJob::class]);
        $scan = scanFor(pdfSubmission(), ScanStatus::Waiting);

        callWebhook($this, webhookPath($scan), json_encode(exportSummaryBody($scan)))->assertOk();

        expect(fakeScanClient()->exported)->toHaveCount(1);
        pushEverything($this, $scan);

        expect($scan->refresh()->status)->toBe(ScanStatus::Completed);
    });

    it('still finishes when a task is unhealthy but every file is there (AC-7)', function () {
        $scan = fetchingScan();
        runExport($scan);
        $id = exportId($scan);
        foreach (['res-a', 'res-b'] as $r) {
            callWebhook($this, exportPath($scan, $id, 'result', $r), '{}');
        }
        callWebhook($this, exportPath($scan, $id, 'text'), '{}');

        callWebhook($this, exportPath($scan, $id, 'completed'), exportCompletedBody($scan, [['isHealthy' => false, 'httpStatusCode' => 500]]))->assertOk();

        expect($scan->refresh()->status)->toBe(ScanStatus::Completed);
    });

    it('completes with an empty ai value and one warning when the alert is missing (AC-8)', function () {
        Log::spy();
        $scan = fetchingScan(['res-a'], ['ai_requested' => true], function (array $body): array {
            $body['notifications'] = ['alerts' => []];

            return $body;
        });
        runExport($scan);

        pushEverything($this, $scan, 1, ['res-a']);

        expect($scan->refresh()->status)->toBe(ScanStatus::Completed)->and($scan->ai_percent)->toBeNull();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m) => str_contains($m, 'AI value'))->once();
    });

    it('reads the Ai key without caring about case and survives a broken additionalData (AC-8)', function () {
        $scan = fetchingScan(['res-a'], [], function (array $body): array {
            $body['notifications']['alerts'][0]['additionalData'] = '{not json';

            return $body;
        });
        runExport($scan);

        pushEverything($this, $scan, 1, ['res-a']);

        expect($scan->refresh()->status)->toBe(ScanStatus::Completed)->and($scan->ai_percent)->toBeNull();
    });

    it('queues a single delete when two finishers race (AC-7)', function () {
        $scan = fetchingScan();
        runExport($scan);
        foreach (['res-a', 'res-b'] as $r) {
            callWebhook($this, exportPath($scan, exportId($scan), 'result', $r), '{}');
        }
        callWebhook($this, exportPath($scan, exportId($scan), 'text'), '{}');

        $finish = app(FinishScan::class);
        $stale = Scan::find($scan->id);
        $finish($scan);
        $finish($stale);

        Queue::assertPushed(DeleteRemoteScanJob::class, 1);
    });
});

describe('failures and retries', function () {
    it('starts the next try at once when a file is missing at the latest completion (AC-9)', function () {
        Queue::fake();
        $scan = fetchingScan();
        runExport($scan);
        callWebhook($this, exportPath($scan, exportId($scan), 'result', 'res-a'), '{}');
        callWebhook($this, exportPath($scan, exportId($scan), 'text'), '{}');

        callWebhook($this, exportPath($scan, exportId($scan), 'completed'), exportCompletedBody($scan))->assertOk();

        expect($scan->refresh()->status)->toBe(ScanStatus::Fetching);
        Queue::assertPushed(StartExportJob::class, fn (StartExportJob $job) => $job->attempt === 2);
    });

    it('ignores a late completion of an older export id (AC-9)', function () {
        Queue::fake();
        $scan = fetchingScan();
        runExport($scan);
        runExport($scan, 2);

        callWebhook($this, exportPath($scan, exportId($scan, 1), 'completed'), exportCompletedBody($scan))->assertOk();

        Queue::assertNotPushed(StartExportJob::class);
        expect(ScanEvent::where('kind', EventKind::ExportCompleted->value)->count())->toBe(1);
    });

    it('changes nothing for a repeated completion of the same export id (AC-6)', function () {
        Queue::fake();
        $scan = fetchingScan();
        runExport($scan);
        $path = exportPath($scan, exportId($scan), 'completed');

        callWebhook($this, $path, exportCompletedBody($scan));
        callWebhook($this, $path, exportCompletedBody($scan));

        Queue::assertPushed(StartExportJob::class, 1);
    });

    it('rejects a completion whose developerPayload is not the scan id (AC-6)', function () {
        $scan = fetchingScan();
        runExport($scan);

        callWebhook($this, exportPath($scan, exportId($scan), 'completed'), ['completed' => true, 'developerPayload' => 'other'])->assertNotFound();
    });

    it('fails with the fixed reason after three accepted tries, keeping the summary (AC-9)', function () {
        Queue::fake();
        $scan = fetchingScan();

        foreach ([1, 2, 3] as $attempt) {
            runExport($scan->refresh(), $attempt);
            callWebhook($this, exportPath($scan, exportId($scan, $attempt), 'completed'), exportCompletedBody($scan));
        }

        $scan->refresh();
        expect($scan->status)->toBe(ScanStatus::Failed)->and($scan->failure_reason)->toBe(AdvanceExport::FAILURE_REASON)
            ->and(ScanArtifact::where('kind', 'summary')->count())->toBe(1);
        Queue::assertPushed(StartExportJob::class, 2);
    });

    it('does not count an unaccepted claim toward the three (AC-9)', function () {
        Queue::fake();
        $scan = fetchingScan();
        foreach ([1, 2] as $attempt) {
            runExport($scan->refresh(), $attempt);
        }
        fakeScanClient()->willExport(new ScanClientUnavailable('down'));
        runExport($scan->refresh(), 3);

        app(AdvanceExport::class)($scan->refresh());

        expect($scan->refresh()->status)->toBe(ScanStatus::Fetching);
    });
});

describe('the throttle', function () {
    it('allows a scan with hundreds of results through (AC-2)', function () {
        $ids = array_map(fn (int $i): string => "res-{$i}", range(1, 120));
        $scan = fetchingScan($ids);
        runExport($scan);

        foreach ($ids as $id) {
            callWebhook($this, exportPath($scan, exportId($scan), 'result', $id), '{}')->assertOk();
        }

        expect(ScanArtifact::where('kind', 'result')->count())->toBe(120);
    });
});
