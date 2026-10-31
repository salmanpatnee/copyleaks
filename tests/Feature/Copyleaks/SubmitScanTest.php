<?php

use App\Enums\ScanStatus;
use App\Jobs\SubmitScanJob;
use App\Models\Scan;
use App\Models\Submission;
use App\Services\Copyleaks\ScanClientUnavailable;
use App\Services\Copyleaks\SubmitResult;
use App\Services\Copyleaks\WebhookToken;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
    RateLimiter::clear('scan-submit');
});

/** Runs the job as if the queue gave it its Nth try. */
function runSubmitJob(Scan $scan, int $attempt = 1): SubmitScanJob
{
    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('attempts')->andReturn($attempt);
    $queueJob->shouldReceive('release')->andReturnNull()->byDefault();
    $queueJob->shouldReceive('isReleased')->andReturnFalse()->byDefault();

    $job = new SubmitScanJob($scan->id);
    $job->setJob($queueJob);
    app()->call([$job, 'handle']);

    return $job;
}

describe('Submit action and route', function () {
    it('creates exactly one submitting scan and queues exactly one job (AC-2, AC-4)', function () {
        Queue::fake();
        $submission = pdfSubmission();

        $this->post(route('submissions.scans.store', $submission), ['include_ai' => true])
            ->assertRedirect(route('submissions.show', $submission));

        $scan = Scan::sole();

        expect($scan->status)->toBe(ScanStatus::Submitting)
            ->and($scan->copyleaks_scan_id)->toMatch('/^[0-9a-z]{26}$/')
            ->and($scan->copyleaks_scan_id)->not->toContain('l')
            ->and($scan->ai_requested)->toBeTrue()
            ->and($scan->is_sandbox)->toBeTrue()
            ->and($scan->webhook_token_hash)->toMatch('/^[0-9a-f]{64}$/');

        Queue::assertPushed(SubmitScanJob::class, 1);
        Queue::assertPushed(SubmitScanJob::class, fn (SubmitScanJob $job) => $job->scanId === $scan->id);
    });

    it('reads the AI checkbox and defaults to ticked (AC-4)', function () {
        Queue::fake();

        $this->post(route('submissions.scans.store', pdfSubmission()), ['include_ai' => false]);
        $this->post(route('submissions.scans.store', pdfSubmission()));

        expect(Scan::orderBy('id')->pluck('ai_requested')->all())->toBe([false, true]);
    });

    it('never stores the token or the override tick (AC-4)', function () {
        Queue::fake();

        $this->post(route('submissions.scans.store', pdfSubmission()), ['confirm_duplicate' => true]);

        expect(json_encode(Scan::sole()->getAttributes()))->not->toContain(app(WebhookToken::class)->for(Scan::sole()->copyleaks_scan_id))
            ->and(Schema::hasColumn('scans', 'confirm_duplicate'))->toBeFalse();
    });

    it('keeps the job row and the scan together in the database queue (AC-2)', function () {
        config(['queue.default' => 'database']);
        Queue::swap(app('queue'));

        $this->post(route('submissions.scans.store', pdfSubmission()))->assertRedirect();

        expect(Scan::count())->toBe(1)->and(DB::table('jobs')->count())->toBe(1);
        $payload = (string) DB::table('jobs')->value('payload');
        expect($payload)->not->toContain(app(WebhookToken::class)->for(Scan::sole()->copyleaks_scan_id));
    });

    it('refuses a second press while a scan is running (AC-2)', function (ScanStatus $status) {
        Queue::fake();
        $submission = pdfSubmission();
        scanFor($submission, $status);

        $this->from('/back')->post(route('submissions.scans.store', $submission))
            ->assertSessionHasErrors('scan');

        expect(Scan::count())->toBe(1);
        Queue::assertNothingPushed();
    })->with([ScanStatus::Submitting, ScanStatus::Waiting, ScanStatus::Fetching]);

    it('creates one scan when the button is pressed twice in a row (AC-2)', function () {
        Queue::fake();
        $submission = pdfSubmission();

        $this->post(route('submissions.scans.store', $submission));
        $this->post(route('submissions.scans.store', $submission))->assertSessionHasErrors('scan');

        expect(Scan::count())->toBe(1);
        Queue::assertPushed(SubmitScanJob::class, 1);
    });

    it('allows a new scan after a failed one and keeps the old scan (AC-1, AC-2)', function () {
        Queue::fake();
        $submission = pdfSubmission();
        scanFor($submission, ScanStatus::Failed, ['failure_reason' => 'x']);

        $this->post(route('submissions.scans.store', $submission))->assertSessionHasNoErrors();

        expect(Scan::count())->toBe(2);
    });

    it('refuses a Word file with a clear message (AC-1)', function () {
        Queue::fake();
        $submission = Submission::factory()->create(['file_path' => 'submissions/x/manuscript.docx', 'page_count' => null]);

        $this->post(route('submissions.scans.store', $submission))
            ->assertSessionHasErrors(['scan' => 'Word files cannot be checked yet. Upload a PDF instead.']);

        expect(Scan::count())->toBe(0);
    });

    it('refuses a finished scan so credits are not spent twice (AC-1)', function () {
        Queue::fake();
        $submission = pdfSubmission();
        scanFor($submission, ScanStatus::Completed);

        $this->post(route('submissions.scans.store', $submission))->assertSessionHasErrors('scan');
        expect(Scan::count())->toBe(1);
    });

    it('returns 404 for an unknown submission', function () {
        $this->post('/submissions/01hzzzzzzzzzzzzzzzzzzzzzzz/scans')->assertNotFound();
    });

    it('is limited to 5 presses a minute (AC-13)', function () {
        Queue::fake();

        foreach (range(1, 5) as $_) {
            $this->post(route('submissions.scans.store', pdfSubmission()))->assertSessionHasNoErrors();
        }

        $this->post(route('submissions.scans.store', pdfSubmission()))->assertSessionHasErrors('scan');
        expect(Scan::count())->toBe(5);
    });
});

describe('duplicates (AC-3)', function () {
    beforeEach(fn () => copyleaksReady(['copyleaks.sandbox' => false]));

    it('refuses a duplicate until the override box is ticked', function () {
        Queue::fake();
        $earlier = pdfSubmission(['file_sha256' => str_repeat('a', 64)]);
        scanFor($earlier, ScanStatus::Completed, ['is_sandbox' => false]);
        $later = pdfSubmission(['file_sha256' => str_repeat('a', 64)]);

        $this->post(route('submissions.scans.store', $later))->assertSessionHasErrors('scan');
        expect(Scan::count())->toBe(1);

        $this->post(route('submissions.scans.store', $later), ['confirm_duplicate' => true])->assertSessionHasNoErrors();
        expect(Scan::count())->toBe(2);
    });

    it('ignores failed scans and sandbox scans when looking for a duplicate', function () {
        Queue::fake();
        $earlier = pdfSubmission(['file_sha256' => str_repeat('b', 64)]);
        scanFor($earlier, ScanStatus::Failed, ['is_sandbox' => false]);
        $sandboxed = pdfSubmission(['file_sha256' => str_repeat('b', 64)]);
        scanFor($sandboxed, ScanStatus::Completed, ['is_sandbox' => true]);
        $later = pdfSubmission(['file_sha256' => str_repeat('b', 64)]);

        $this->post(route('submissions.scans.store', $later))->assertSessionHasNoErrors();
        expect(Scan::count())->toBe(3);
    });

    it('skips the duplicate check for a sandbox scan', function () {
        Queue::fake();
        copyleaksReady(['copyleaks.sandbox' => true]);
        $earlier = pdfSubmission(['file_sha256' => str_repeat('c', 64)]);
        scanFor($earlier, ScanStatus::Completed, ['is_sandbox' => false]);
        $later = pdfSubmission(['file_sha256' => str_repeat('c', 64)]);

        $this->post(route('submissions.scans.store', $later))->assertSessionHasNoErrors();
        expect(Scan::count())->toBe(2);
    });
});

describe('not set up (AC-13)', function () {
    it('creates no scan when a setting is missing or the address is not https', function (array $override) {
        Queue::fake();
        copyleaksReady($override);

        $this->post(route('submissions.scans.store', pdfSubmission()))
            ->assertSessionHasErrors(['scan' => 'Copyleaks is not set up. Ask the developer to add the Copyleaks settings.']);

        expect(Scan::count())->toBe(0);
        Queue::assertNothingPushed();
    })->with([
        'no email' => [['copyleaks.email' => '']],
        'no key' => [['copyleaks.api_key' => '']],
        'no secret' => [['copyleaks.webhook_secret' => '']],
        'http address' => [['copyleaks.webhook_url' => 'http://tool.example.test']],
    ]);
});

describe('submit job', function () {
    it('sends the file and moves the scan to waiting on 201 (AC-5)', function () {
        $submission = pdfSubmission(['original_name' => 'Essay.pdf']);
        $scan = scanFor($submission, ScanStatus::Submitting, ['ai_requested' => true]);

        runSubmitJob($scan);

        $sent = fakeScanClient()->submitted;
        expect($sent)->toHaveCount(1)
            ->and($sent[0]->scanId)->toBe($scan->copyleaks_scan_id)
            ->and($sent[0]->fileName)->toBe('Essay.pdf')
            ->and($sent[0]->contents)->toBe(Storage::disk('local')->get($submission->file_path))
            ->and($sent[0]->options['developerPayload'])->toBe($scan->copyleaks_scan_id)
            ->and($sent[0]->options['aiGeneratedText']['detect'])->toBeTrue();

        $scan->refresh();
        expect($scan->status)->toBe(ScanStatus::Waiting)->and($scan->submitted_at)->not->toBeNull();
    });

    it('moves to waiting on 409 because an earlier try got through (AC-5, AC-7)', function () {
        $scan = scanFor(pdfSubmission(), ScanStatus::Submitting);
        fakeScanClient()->willSubmit(SubmitResult::conflict());

        runSubmitJob($scan, 2);

        expect($scan->refresh()->status)->toBe(ScanStatus::Waiting);
    });

    it('exits quietly when the scan is no longer submitting (AC-5)', function () {
        $scan = scanFor(pdfSubmission(), ScanStatus::Fetching);

        runSubmitJob($scan);

        expect(fakeScanClient()->submitted)->toBeEmpty();
    });

    it('fails the scan with the fixed reason when the file is missing, and sends nothing (AC-5)', function () {
        $submission = Submission::factory()->create();
        $scan = scanFor($submission, ScanStatus::Submitting);

        runSubmitJob($scan);

        expect(fakeScanClient()->submitted)->toBeEmpty();
        expect($scan->refresh()->status)->toBe(ScanStatus::Failed)
            ->and($scan->failure_reason)->toBe('The saved file could not be read.');
    });

    it('fails the scan at once on a first try refusal and never retries (AC-7)', function () {
        $scan = scanFor(pdfSubmission(), ScanStatus::Submitting);
        fakeScanClient()->willSubmit(SubmitResult::refused('402: Not enough credits'));

        runSubmitJob($scan, 1);

        expect($scan->refresh()->status)->toBe(ScanStatus::Failed)
            ->and($scan->failure_reason)->toBe('402: Not enough credits')
            ->and(fakeScanClient()->submitted)->toHaveCount(1);
    });

    it('leaves the scan submitting when a later try is refused, and warns with the scan id only (AC-7)', function () {
        Log::spy();
        $scan = scanFor(pdfSubmission(), ScanStatus::Submitting);
        fakeScanClient()->willSubmit(SubmitResult::refused('429: Slow down'));

        runSubmitJob($scan, 2);

        expect($scan->refresh()->status)->toBe(ScanStatus::Submitting)->and($scan->failure_reason)->toBeNull();
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $context === ['scan_id' => $scan->copyleaks_scan_id]);
    });

    it('releases for a retry on a timeout or 5xx, with the same scan id each time (AC-7)', function (int $attempt, int $delay) {
        $scan = scanFor(pdfSubmission(), ScanStatus::Submitting);
        fakeScanClient()->willSubmit(new ScanClientUnavailable('timeout'));

        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('attempts')->andReturn($attempt);
        $queueJob->shouldReceive('release')->once()->with($delay);
        $job = new SubmitScanJob($scan->id);
        $job->setJob($queueJob);
        app()->call([$job, 'handle']);

        expect($scan->refresh()->status)->toBe(ScanStatus::Submitting)
            ->and(fakeScanClient()->submitted[0]->scanId)->toBe($scan->copyleaks_scan_id);
    })->with([[1, 60], [2, 120]]);

    it('gives up after the third try: the exception lands the job in failed jobs and the scan stays submitting (AC-7)', function () {
        $scan = scanFor(pdfSubmission(), ScanStatus::Submitting);
        fakeScanClient()->willSubmit(new ScanClientUnavailable('timeout'));

        expect(fn () => runSubmitJob($scan, 3))->toThrow(ScanClientUnavailable::class);
        expect($scan->refresh()->status)->toBe(ScanStatus::Submitting);
    });

    it('has the agreed retry rules (AC-7)', function () {
        $scan = scanFor(pdfSubmission(), ScanStatus::Submitting);
        $job = new SubmitScanJob($scan->id);

        expect($job->tries)->toBe(3)
            ->and($job->retryUntil()->equalTo($scan->created_at->addMinutes(15)))->toBeTrue();
    });

    it('never sends after the 15 minute deadline (AC-7)', function () {
        $scan = scanFor(pdfSubmission(), ScanStatus::Submitting);
        Scan::whereKey($scan->id)->update(['created_at' => now()->subMinutes(16)]);

        runSubmitJob($scan->refresh());

        expect(fakeScanClient()->submitted)->toBeEmpty();
        expect($scan->refresh()->status)->toBe(ScanStatus::Submitting);
    });

    it('runs end to end on the sync queue through the Submit press (AC-2, AC-5)', function () {
        $submission = pdfSubmission();

        $this->post(route('submissions.scans.store', $submission))->assertSessionHasNoErrors();

        expect(Scan::sole()->status)->toBe(ScanStatus::Waiting)
            ->and(fakeScanClient()->submitted)->toHaveCount(1);
    });

    it('does not log keys, tokens or file text (AC-14)', function () {
        Log::spy();
        $scan = scanFor(pdfSubmission(), ScanStatus::Submitting);
        fakeScanClient()->willSubmit(new ScanClientUnavailable('timeout'));

        runSubmitJob($scan, 1);

        $token = app(WebhookToken::class)->for($scan->copyleaks_scan_id);
        Log::shouldNotHaveReceived('info', [Mockery::on(fn () => true), Mockery::on(fn ($c) => str_contains(json_encode($c), $token))]);
        Log::shouldHaveReceived('warning')->withArgs(fn ($m, $c) => ! str_contains(json_encode($c), 'test-webhook-secret-not-real')
            && ! str_contains(json_encode($c), $token)
            && ! str_contains(json_encode($c), 'fake manuscript bytes'));
    });
});
