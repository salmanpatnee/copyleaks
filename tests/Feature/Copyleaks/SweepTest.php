<?php

use App\Actions\SubmitScan;
use App\Console\Commands\SweepFetchingScans;
use App\Enums\EventKind;
use App\Enums\ScanStatus;
use App\Jobs\DeleteRemoteScanJob;
use App\Jobs\StartExportJob;
use App\Jobs\SubmitScanJob;
use App\Models\Scan;
use App\Models\ScanEvent;
use App\Services\Copyleaks\FetchingScanSweeper;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once __DIR__.'/../../Support/CopyleaksTestHelpers.php';

beforeEach(function () {
    Storage::fake('local');
    copyleaksReady();
    Queue::fake();
});

function sweep(): void
{
    test()->artisan('copyleaks:sweep-fetching')->assertSuccessful();
}

function pushAllFiles(Scan $scan): void
{
    $id = exportId($scan);
    foreach (['res-a', 'res-b'] as $r) {
        callWebhook(test(), exportPath($scan, $id, 'result', $r), '{}')->assertOk();
    }
    callWebhook(test(), exportPath($scan, $id, 'text'), '{}')->assertOk();
}

/** Moves the claim of one try back in time so it looks old. */
function ageClaim(Scan $scan, int $attempt, int $minutes, bool $handled = true): void
{
    $when = now()->subMinutes($minutes);
    ScanEvent::where('scan_id', $scan->id)->where('kind', EventKind::ExportStarted->value)->where('status', exportId($scan, $attempt))
        ->update(['received_at' => $when, 'handled_at' => $handled ? $when : null]);
}

describe('copyleaks:sweep-fetching', function () {
    it('queues attempt 1 for a fetching scan with no export, and never submits (AC-10)', function () {
        $scan = fetchingScan();

        sweep();

        Queue::assertPushed(StartExportJob::class, fn (StartExportJob $job) => $job->scanId === $scan->id && $job->attempt === 1);
        Queue::assertNotPushed(SubmitScanJob::class);
        expect(Scan::count())->toBe(1)->and(fakeScanClient()->submitted)->toBeEmpty();
    });

    it('finishes a scan whose files are all there before anything else (AC-10)', function () {
        $scan = fetchingScan();
        runExport($scan);
        pushAllFiles($scan);
        Scan::whereKey($scan->id)->update(['status' => 'fetching', 'completed_at' => null]);
        ScanEvent::where('scan_id', $scan->id)->where('kind', 'status')->update(['received_at' => now()->subHours(60)]);

        sweep();

        expect($scan->refresh()->status)->toBe(ScanStatus::Completed);
    });

    it('fails a scan 49 hours after the summary with a file missing (AC-10)', function () {
        $scan = fetchingScan();
        ScanEvent::where('scan_id', $scan->id)->where('kind', 'status')->update(['received_at' => now()->subHours(49)]);

        sweep();

        expect($scan->refresh()->status)->toBe(ScanStatus::Failed)->and($scan->failure_reason)->toBe(FetchingScanSweeper::TOO_LATE_REASON);
        Queue::assertNotPushed(StartExportJob::class);
    });

    it('leaves a recent attempt alone (AC-10)', function () {
        $scan = fetchingScan();
        runExport($scan);
        ageClaim($scan, 1, 5);

        sweep();

        Queue::assertNotPushed(StartExportJob::class);
        expect($scan->refresh()->status)->toBe(ScanStatus::Fetching);
    });

    it('starts the next attempt when an accepted one is over 15 minutes old (AC-10)', function () {
        $scan = fetchingScan();
        runExport($scan);
        ageClaim($scan, 1, 20);

        sweep();

        Queue::assertPushed(StartExportJob::class, fn (StartExportJob $job) => $job->attempt === 2);
        expect($scan->refresh()->status)->toBe(ScanStatus::Fetching);
    });

    it('queues the same attempt again when the claim was never accepted (AC-10)', function () {
        $scan = fetchingScan();
        runExport($scan);
        ageClaim($scan, 1, 20, handled: false);

        sweep();

        Queue::assertPushed(StartExportJob::class, fn (StartExportJob $job) => $job->attempt === 1);
    });

    it('leaves an unaccepted claim alone while it is recent (AC-10)', function () {
        $scan = fetchingScan();
        runExport($scan);
        ageClaim($scan, 1, 2, handled: false);

        sweep();

        Queue::assertNotPushed(StartExportJob::class);
    });

    it('starts the next attempt right away when the latest one has its completion (AC-10)', function () {
        $scan = fetchingScan();
        runExport($scan);
        callWebhook($this, exportPath($scan, exportId($scan), 'completed'), exportCompletedBody($scan))->assertOk();
        Queue::fake();

        sweep();

        Queue::assertPushed(StartExportJob::class, fn (StartExportJob $job) => $job->attempt === 2);
    });

    it('fails with the fixed reason after the third attempt has ended (AC-9, AC-10)', function () {
        $scan = fetchingScan();
        foreach ([1, 2, 3] as $attempt) {
            runExport($scan->refresh(), $attempt);
            ageClaim($scan, $attempt, 20);
        }

        sweep();

        expect($scan->refresh()->status)->toBe(ScanStatus::Failed)->and($scan->failure_reason)->toContain('Export incomplete after 3 tries');
    });

    it('ignores scans that are not fetching', function () {
        scanFor(pdfSubmission(), ScanStatus::Completed);
        scanFor(pdfSubmission(), ScanStatus::Failed);

        sweep();

        Queue::assertNothingPushed();
    });

    it('reports how many fetching scans it looked at', function () {
        fetchingScan();
        fetchingScan();

        $this->artisan('copyleaks:sweep-fetching')->expectsOutput('Looked at 2 fetching scan(s).')->assertSuccessful();
    });

    it('never calls the Copyleaks client itself', function () {
        fetchingScan();

        sweep();

        fakeScanClient()->assertNothingSent();
    });
});

it('is scheduled every five minutes, next to the queue worker every minute (AC-10)', function () {
    $events = collect(app(Schedule::class)->events());

    $sweep = $events->first(fn ($e) => str_contains($e->command, 'copyleaks:sweep-fetching'));
    $worker = $events->first(fn ($e) => str_contains($e->command, 'queue:work'));

    expect($sweep->expression)->toBe('*/5 * * * *')->and($worker->expression)->toBe('* * * * *');
});

arch('the sweep, export and delete code never reach the submit code (AC-10, AC-13)')
    ->expect([FetchingScanSweeper::class, SweepFetchingScans::class, StartExportJob::class, DeleteRemoteScanJob::class])
    ->not->toUse([SubmitScan::class, SubmitScanJob::class]);
