<?php

use App\Actions\ResendScanResult;
use App\Enums\ScanStatus;
use App\Models\Scan;
use App\Models\Submission;
use App\Services\Copyleaks\ResendEligibility;
use App\Services\Copyleaks\ResendResult;
use App\Services\Copyleaks\ScanClientUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once __DIR__.'/../../Support/CopyleaksTestHelpers.php';

beforeEach(function () {
    Storage::fake('local');
    copyleaksReady();
});

function waitingScanOlderThan(int $minutes): Scan
{
    $scan = scanFor(pdfSubmission(), ScanStatus::Waiting);
    Scan::whereKey($scan->id)->update(['submitted_at' => now()->subMinutes($minutes)]);

    return $scan->refresh();
}

describe('who can resend (AC-12)', function () {
    it('is allowed for a waiting scan after 10 minutes, not before', function () {
        expect(app(ResendEligibility::class)->canResend(waitingScanOlderThan(9)))->toBeFalse()
            ->and(app(ResendEligibility::class)->canResend(waitingScanOlderThan(10)))->toBeTrue();
    });

    it('counts from created_at when submitted_at is empty', function () {
        $scan = scanFor(pdfSubmission(), ScanStatus::Waiting, ['submitted_at' => null]);
        Scan::whereKey($scan->id)->update(['created_at' => now()->subMinutes(11)]);

        expect(app(ResendEligibility::class)->canResend($scan->refresh()))->toBeTrue();
    });

    it('is allowed for a submitting scan only after the 15 minute job deadline', function () {
        $scan = scanFor(pdfSubmission(), ScanStatus::Submitting);
        Scan::whereKey($scan->id)->update(['created_at' => now()->subMinutes(14)]);
        expect(app(ResendEligibility::class)->canResend($scan->refresh()))->toBeFalse();

        Scan::whereKey($scan->id)->update(['created_at' => now()->subMinutes(15)]);
        expect(app(ResendEligibility::class)->canResend($scan->refresh()))->toBeTrue();
    });

    it('is never allowed for fetching, completed or failed scans', function (ScanStatus $status) {
        $scan = scanFor(pdfSubmission(), $status);
        Scan::whereKey($scan->id)->update(['created_at' => now()->subHour(), 'submitted_at' => now()->subHour()]);

        expect(app(ResendEligibility::class)->canResend($scan->refresh()))->toBeFalse();
    })->with([ScanStatus::Fetching, ScanStatus::Completed, ScanStatus::Failed]);
});

describe('the resend action', function () {
    it('asks Copyleaks and does not create a scan on 202 (AC-12)', function () {
        $scan = waitingScanOlderThan(11);

        $this->post(route('submissions.scans.resend', $scan->submission))
            ->assertRedirect(route('submissions.show', $scan->submission))
            ->assertSessionHas('notice', 'We asked Copyleaks to send the result again.');

        expect(fakeScanClient()->resent)->toBe([$scan->copyleaks_scan_id])
            ->and(fakeScanClient()->submitted)->toBeEmpty()
            ->and(Scan::count())->toBe(1)
            ->and($scan->refresh()->status)->toBe(ScanStatus::Waiting);
    });

    it('says the result is not ready on 400 and leaves the scan alone (AC-12)', function () {
        $scan = waitingScanOlderThan(11);
        fakeScanClient()->willResend(ResendResult::NotReady);

        $this->post(route('submissions.scans.resend', $scan->submission))
            ->assertSessionHas('notice', 'Copyleaks says the result is not ready yet.');

        expect($scan->refresh()->status)->toBe(ScanStatus::Waiting);
    });

    it('fails the scan with the fixed reason on 404 so staff can submit again (AC-12)', function () {
        $scan = waitingScanOlderThan(11);
        fakeScanClient()->willResend(ResendResult::NotFound);

        $this->post(route('submissions.scans.resend', $scan->submission))->assertRedirect();

        expect($scan->refresh()->status)->toBe(ScanStatus::Failed)
            ->and($scan->failure_reason)->toBe('Copyleaks has no record of this scan (never received or already expired).');
    });

    it('works for a submitting scan past the deadline and fails it on 404 (AC-12)', function () {
        $scan = scanFor(pdfSubmission(), ScanStatus::Submitting);
        Scan::whereKey($scan->id)->update(['created_at' => now()->subMinutes(16)]);
        fakeScanClient()->willResend(ResendResult::NotFound);

        $this->post(route('submissions.scans.resend', $scan->submission))->assertRedirect();

        expect($scan->refresh()->status)->toBe(ScanStatus::Failed);
    });

    it('is refused when it is too early, without calling Copyleaks (AC-12)', function () {
        $scan = waitingScanOlderThan(2);

        $this->post(route('submissions.scans.resend', $scan->submission))->assertSessionHasErrors('scan');

        expect(fakeScanClient()->resent)->toBeEmpty();
    });

    it('works once per 10 minutes per scan (AC-12)', function () {
        $scan = waitingScanOlderThan(11);

        $this->post(route('submissions.scans.resend', $scan->submission))->assertSessionHasNoErrors();
        $this->post(route('submissions.scans.resend', $scan->submission))->assertSessionHasErrors('scan');

        expect(fakeScanClient()->resent)->toHaveCount(1)
            ->and(app(ResendEligibility::class)->canResend($scan))->toBeFalse();

        RateLimiter::clear('scan-resend:'.$scan->id);
        expect(app(ResendEligibility::class)->canResend($scan))->toBeTrue();
    });

    it('gives the allowance back when Copyleaks cannot be reached', function () {
        $scan = waitingScanOlderThan(11);
        fakeScanClient()->willResend(new ScanClientUnavailable('down'));

        $this->post(route('submissions.scans.resend', $scan->submission))->assertSessionHasErrors('scan');

        expect(app(ResendEligibility::class)->canResend($scan))->toBeTrue();
    });

    it('is refused when Copyleaks is not set up', function () {
        copyleaksReady(['copyleaks.webhook_secret' => '']);
        $scan = waitingScanOlderThan(11);

        $this->post(route('submissions.scans.resend', $scan->submission))->assertSessionHasErrors('scan');

        expect(fakeScanClient()->resent)->toBeEmpty();
    });

    it('is refused when there is no scan', function () {
        $this->post(route('submissions.scans.resend', pdfSubmission()))->assertSessionHasErrors('scan');
        expect(app(ResendScanResult::class))->toBeInstanceOf(ResendScanResult::class);
    });

    it('returns 404 for an unknown submission', function () {
        $this->post('/submissions/01hzzzzzzzzzzzzzzzzzzzzzzz/scans/resend')->assertNotFound();
    });
});

describe('the saved page props (AC-1, AC-11, AC-12)', function () {
    it('shows the Submit panel data for a PDF with no scan, with the credit estimate', function () {
        $submission = pdfSubmission(['page_count' => 10]);

        $this->get(route('submissions.show', $submission))->assertInertia(fn (Assert $page) => $page
            ->component('submissions/Show')
            ->where('scan', null)
            ->where('can_submit', true)
            ->where('estimated_credits', 20)
            ->where('scan_duplicate', null));
    });

    it('shows no panel and no estimate for a Word file', function () {
        $submission = Submission::factory()->create(['file_path' => 'submissions/x/manuscript.docx', 'page_count' => null]);

        $this->get(route('submissions.show', $submission))->assertInertia(fn (Assert $page) => $page
            ->where('can_submit', false)
            ->where('estimated_credits', null));
    });

    it('shows the panel again after a failed scan, with the reason', function () {
        $submission = pdfSubmission();
        scanFor($submission, ScanStatus::Failed, ['failure_reason' => '402: No credits']);

        $this->get(route('submissions.show', $submission))->assertInertia(fn (Assert $page) => $page
            ->where('can_submit', true)
            ->where('scan.status', 'failed')
            ->where('scan.label', 'Failed')
            ->where('scan.failure_reason', '402: No credits')
            ->where('scan.is_polling', false));
    });

    it('polls while the scan runs (until completed or failed) and hides the panel while a scan runs', function (ScanStatus $status, bool $polling, bool $running) {
        $submission = pdfSubmission();
        scanFor($submission, $status);

        $this->get(route('submissions.show', $submission))->assertInertia(fn (Assert $page) => $page
            ->where('scan.is_polling', $polling)
            ->where('scan.is_running', $running)
            ->where('can_submit', false)
            ->where('scan.label', $status->label()));
    })->with([
        [ScanStatus::Submitting, true, true],
        [ScanStatus::Waiting, true, true],
        [ScanStatus::Fetching, true, true],
        [ScanStatus::Completed, false, false],
    ]);

    it('shows no similarity or AI score anywhere (AC-11)', function () {
        $submission = pdfSubmission();
        scanFor($submission, ScanStatus::Completed, ['similarity_percent' => 40, 'ai_percent' => 70]);

        $response = $this->get(route('submissions.show', $submission));

        expect(json_encode($response->viewData('page')['props']))->not->toContain('similarity')->not->toContain('ai_percent');
    });

    it('offers resend only when it is allowed', function () {
        $fresh = pdfSubmission();
        scanFor($fresh, ScanStatus::Waiting);
        $old = waitingScanOlderThan(11)->submission;

        $this->get(route('submissions.show', $fresh))->assertInertia(fn (Assert $page) => $page->where('scan.can_resend', false));
        $this->get(route('submissions.show', $old))->assertInertia(fn (Assert $page) => $page->where('scan.can_resend', true));
    });

    it('links the newest earlier scanned submission as a duplicate on a live account', function () {
        copyleaksReady(['copyleaks.sandbox' => false]);
        $earlier = pdfSubmission(['file_sha256' => str_repeat('d', 64), 'original_name' => 'first.pdf']);
        scanFor($earlier, ScanStatus::Completed, ['is_sandbox' => false]);
        $later = pdfSubmission(['file_sha256' => str_repeat('d', 64)]);

        $this->get(route('submissions.show', $later))->assertInertia(fn (Assert $page) => $page
            ->where('scan_duplicate.public_id', $earlier->public_id)
            ->where('scan_duplicate.original_name', 'first.pdf'));
    });
});
