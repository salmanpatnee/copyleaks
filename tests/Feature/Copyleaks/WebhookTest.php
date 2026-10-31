<?php

use App\Enums\ArtifactKind;
use App\Enums\ScanStatus;
use App\Events\ScanResultsArrived;
use App\Models\Scan;
use App\Models\ScanArtifact;
use App\Models\ScanEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once __DIR__.'/../../Support/CopyleaksTestHelpers.php';

beforeEach(function () {
    Storage::fake('local');
    copyleaksReady();
    RateLimiter::clear('copyleaks-webhook');
});

function summaryPath(Scan $scan): string
{
    return "scans/{$scan->copyleaks_scan_id}/summary.json.gz";
}

describe('a valid completed call', function () {
    it('saves the exact body, then the artifact, event and move together, then fires the event once (AC-9, AC-10)', function (ScanStatus $from) {
        Event::fake([ScanResultsArrived::class]);
        $scan = scanFor(pdfSubmission(), $from);
        $raw = json_encode(completedBody($scan), JSON_UNESCAPED_SLASHES);

        callWebhook($this, webhookPath($scan), $raw)->assertOk()->assertContent('');

        $stored = Storage::disk('local')->get(summaryPath($scan));
        expect(gzdecode($stored))->toBe($raw);

        $artifact = ScanArtifact::sole();
        expect($artifact->kind)->toBe(ArtifactKind::Summary)
            ->and($artifact->source_id)->toBe('')
            ->and($artifact->path)->toBe(summaryPath($scan))
            ->and($artifact->sha256)->toBe(hash('sha256', $stored))
            ->and($artifact->size_bytes)->toBe(strlen($stored));

        $event = ScanEvent::sole();
        expect($event->status)->toBe('completed')
            ->and($event->handled_at)->not->toBeNull()
            ->and($event->payload['scannedDocument'])->toBe(['scanId' => $scan->copyleaks_scan_id, 'totalWords' => 120, 'credits' => 1])
            ->and($event->payload['results']['score']['aggregatedScore'])->toBe(12.5)
            ->and($event->payload['alerts'])->toBe(['suspected-character-replacement'])
            ->and($event->payload)->not->toHaveKey('downloadableReport');

        expect($scan->refresh()->status)->toBe(ScanStatus::Fetching);
        Event::assertDispatchedTimes(ScanResultsArrived::class, 1);
    })->with([ScanStatus::Waiting, ScanStatus::Submitting]);

    it('keeps submitted_at empty when the scan goes straight from submitting to fetching (AC-10)', function () {
        $scan = scanFor(pdfSubmission(), ScanStatus::Submitting);

        callWebhook($this, webhookPath($scan), completedBody($scan))->assertOk();

        expect($scan->refresh()->submitted_at)->toBeNull();
    });

    it('answers 200 and changes nothing on a repeat (AC-9)', function () {
        Event::fake([ScanResultsArrived::class]);
        $scan = scanFor(pdfSubmission(), ScanStatus::Waiting);
        $body = completedBody($scan);

        callWebhook($this, webhookPath($scan), $body)->assertOk();
        callWebhook($this, webhookPath($scan), $body)->assertOk();

        expect(ScanEvent::count())->toBe(1)->and(ScanArtifact::count())->toBe(1);
        Event::assertDispatchedTimes(ScanResultsArrived::class, 1);
    });

    it('writes the file again on a repeat only when it is missing (AC-9)', function () {
        $scan = scanFor(pdfSubmission(), ScanStatus::Waiting);
        $body = completedBody($scan);

        callWebhook($this, webhookPath($scan), $body)->assertOk();
        Storage::disk('local')->delete(summaryPath($scan));

        callWebhook($this, webhookPath($scan), $body)->assertOk();

        expect(Storage::disk('local')->exists(summaryPath($scan)))->toBeTrue()
            ->and(ScanArtifact::count())->toBe(1);
    });

    it('leaves no temp files behind', function () {
        $scan = scanFor(pdfSubmission(), ScanStatus::Waiting);

        callWebhook($this, webhookPath($scan), completedBody($scan))->assertOk();

        expect(Storage::disk('local')->allFiles("scans/{$scan->copyleaks_scan_id}"))->toBe([summaryPath($scan)]);
    });

    it('does not call Copyleaks (AC-9)', function () {
        $scan = scanFor(pdfSubmission(), ScanStatus::Waiting);

        callWebhook($this, webhookPath($scan), completedBody($scan))->assertOk();

        expect(fakeScanClient()->submitted)->toBeEmpty()->and(fakeScanClient()->resent)->toBeEmpty();
    });

    it('needs no session and no CSRF token (AC-8)', function () {
        $scan = scanFor(pdfSubmission(), ScanStatus::Waiting);

        $response = callWebhook($this, webhookPath($scan), completedBody($scan))->assertOk();

        // The web group starts a session and sets cookies. This route must not.
        expect($response->headers->getCookies())->toBeEmpty();
    });
});

describe('finished scans (AC-10)', function () {
    it('keeps the summary and warns when completed arrives for a failed scan', function () {
        Log::spy();
        Event::fake([ScanResultsArrived::class]);
        $scan = scanFor(pdfSubmission(), ScanStatus::Failed, ['failure_reason' => 'x']);

        callWebhook($this, webhookPath($scan), completedBody($scan))->assertOk();

        expect($scan->refresh()->status)->toBe(ScanStatus::Failed)
            ->and(ScanArtifact::count())->toBe(1)
            ->and(Storage::disk('local')->exists(summaryPath($scan)))->toBeTrue()
            ->and(ScanEvent::count())->toBe(1);
        Log::shouldHaveReceived('warning')->withArgs(fn ($m, $c) => $c === ['scan_id' => $scan->copyleaks_scan_id]);
        Event::assertNotDispatched(ScanResultsArrived::class);
    });

    it('saves a call for a completed scan as an event but moves nothing', function (string $status) {
        $scan = scanFor(pdfSubmission(), ScanStatus::Completed, ['completed_at' => now()]);
        $body = $status === 'error' ? errorBody($scan) : completedBody($scan);

        callWebhook($this, webhookPath($scan, $status), $body)->assertOk();

        expect($scan->refresh()->status)->toBe(ScanStatus::Completed)->and(ScanEvent::count())->toBe(1);
    })->with(['completed', 'error']);

    it('does not move a fetching scan again on completed', function () {
        Event::fake([ScanResultsArrived::class]);
        $scan = scanFor(pdfSubmission(), ScanStatus::Fetching);

        callWebhook($this, webhookPath($scan), completedBody($scan))->assertOk();

        expect($scan->refresh()->status)->toBe(ScanStatus::Fetching);
        Event::assertNotDispatched(ScanResultsArrived::class);
    });
});

describe('an error call', function () {
    it('fails a submitting, waiting or fetching scan with the code and message (AC-10)', function (ScanStatus $from) {
        $scan = scanFor(pdfSubmission(), $from);

        callWebhook($this, webhookPath($scan, 'error'), errorBody($scan))->assertOk();

        expect($scan->refresh()->status)->toBe(ScanStatus::Failed)
            ->and($scan->failure_reason)->toBe('7: Something broke');

        $event = ScanEvent::sole();
        expect($event->status)->toBe('error')
            ->and($event->payload['error'])->toBe(['type' => 'api_error', 'id' => 'bad', 'code' => 7, 'message' => 'Something broke'])
            ->and(ScanArtifact::count())->toBe(0);
    })->with([ScanStatus::Submitting, ScanStatus::Waiting, ScanStatus::Fetching]);

    it('does nothing more on a repeat', function () {
        $scan = scanFor(pdfSubmission(), ScanStatus::Waiting);

        callWebhook($this, webhookPath($scan, 'error'), errorBody($scan))->assertOk();
        callWebhook($this, webhookPath($scan, 'error'), errorBody($scan))->assertOk();

        expect(ScanEvent::count())->toBe(1);
    });
});

describe('checks that return the same empty 404 and save nothing (AC-8)', function () {
    function assertNothingSaved(Scan $scan): void
    {
        expect(ScanEvent::count())->toBe(0)
            ->and(ScanArtifact::count())->toBe(0)
            ->and(Storage::disk('local')->exists(summaryPath($scan)))->toBeFalse()
            ->and($scan->refresh()->status)->toBe(ScanStatus::Waiting);
    }

    it('rejects a wrong token', function () {
        $scan = scanFor(pdfSubmission());

        callWebhook($this, webhookPath($scan, 'completed', str_repeat('0', 64)), completedBody($scan))
            ->assertNotFound()->assertContent('');
        assertNothingSaved($scan);
    });

    it('rejects a wrong or missing secret header', function (?string $secret) {
        $scan = scanFor(pdfSubmission());
        $headers = $secret === null ? ['HTTP_X_WEBHOOK_SECRET' => ''] : ['HTTP_X_WEBHOOK_SECRET' => $secret];

        callWebhook($this, webhookPath($scan), completedBody($scan), $headers)->assertNotFound()->assertContent('');
        assertNothingSaved($scan);
    })->with([[null], ['not-the-secret']]);

    it('rejects every call when no secret is configured', function () {
        copyleaksReady(['copyleaks.webhook_secret' => '']);
        $scan = scanFor(pdfSubmission());

        callWebhook($this, webhookPath($scan), completedBody($scan), ['HTTP_X_WEBHOOK_SECRET' => ''])->assertNotFound();
        assertNothingSaved($scan);
    });

    it('rejects an unknown scan', function () {
        $scan = scanFor(pdfSubmission());
        $path = '/webhooks/copyleaks/01unknownscanid0000000000/'.str_repeat('a', 64).'/completed';

        callWebhook($this, $path, completedBody($scan))->assertNotFound()->assertContent('');
        assertNothingSaved($scan);
    });

    it('rejects invalid JSON', function (string $raw) {
        $scan = scanFor(pdfSubmission());

        callWebhook($this, webhookPath($scan), $raw)->assertNotFound()->assertContent('');
        assertNothingSaved($scan);
    })->with(['{not json', '', '"just a string"']);

    it('rejects a developerPayload that is not the scan id', function () {
        $scan = scanFor(pdfSubmission());

        callWebhook($this, webhookPath($scan), completedBody($scan, ['developerPayload' => 'someone-else']))
            ->assertNotFound()->assertContent('');
        assertNothingSaved($scan);
    });

    it('rejects a missing developerPayload', function () {
        $scan = scanFor(pdfSubmission());
        $body = completedBody($scan);
        unset($body['developerPayload']);

        callWebhook($this, webhookPath($scan), $body)->assertNotFound();
        assertNothingSaved($scan);
    });

    it('rejects a status other than completed or error', function (string $status) {
        $scan = scanFor(pdfSubmission());

        callWebhook($this, webhookPath($scan, $status), completedBody($scan))->assertNotFound()->assertContent('');
        assertNothingSaved($scan);
    })->with(['creditschecked', 'indexed', 'COMPLETED', 'other']);

    it('answers GET with 405 and nothing else leaks', function () {
        $scan = scanFor(pdfSubmission());

        $this->get(webhookPath($scan))->assertStatus(405);
    });
});

describe('size and rate limits (AC-8)', function () {
    it('is set to 10 MB', function () {
        expect(config('copyleaks.max_webhook_bytes'))->toBe(10 * 1024 * 1024);
    });

    it('returns 413 when Content-Length is over the limit, once the token passes', function () {
        config(['copyleaks.max_webhook_bytes' => 100]);
        $scan = scanFor(pdfSubmission());

        callWebhook($this, webhookPath($scan), completedBody($scan), ['CONTENT_LENGTH' => 200])->assertStatus(413);

        expect(ScanEvent::count())->toBe(0)->and(ScanArtifact::count())->toBe(0);
    });

    it('does not reveal the size rule to a caller with a wrong token', function () {
        config(['copyleaks.max_webhook_bytes' => 100]);
        $scan = scanFor(pdfSubmission());

        callWebhook($this, webhookPath($scan, 'completed', 'bad'), completedBody($scan), ['CONTENT_LENGTH' => 200])->assertNotFound()->assertContent('');
    });

    it('limits calls to 30 a minute per scan id and lets other scans through', function () {
        $scan = scanFor(pdfSubmission());
        $other = scanFor(pdfSubmission());

        foreach (range(1, 30) as $_) {
            callWebhook($this, webhookPath($scan), '{bad')->assertNotFound();
        }

        callWebhook($this, webhookPath($scan), '{bad')->assertStatus(429);
        callWebhook($this, webhookPath($other), '{bad')->assertNotFound();
    });
});

describe('logs (AC-8, AC-14)', function () {
    it('log only the scan id and a status word', function () {
        Log::spy();
        $scan = scanFor(pdfSubmission());

        callWebhook($this, webhookPath($scan), completedBody($scan))->assertOk();

        Log::shouldHaveReceived('info')->withArgs(fn ($m, $c) => $c === ['scan_id' => $scan->copyleaks_scan_id, 'status' => 'completed']);
    });
});
