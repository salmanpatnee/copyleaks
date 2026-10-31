<?php

use App\Actions\DeleteSubmission;
use App\Exceptions\SubmissionHasRunningScan;
use App\Models\Report;
use App\Models\Scan;
use App\Models\ScanArtifact;
use App\Models\ScanEvent;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function submissionWithEverything(string $state = 'completed'): Submission
{
    $submission = Submission::factory()->create();
    $scan = Scan::factory()->for($submission)->{$state}()->create();
    ScanArtifact::factory()->for($scan)->create();
    ScanEvent::factory()->for($scan)->create();
    Report::factory()->for($scan)->create();

    return $submission;
}

it('has foreign keys switched on, so cascades are real (AC-7)', function () {
    // If this fails, every cascade test below would pass for the wrong reason or not at all.
    expect((int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys)->toBe(1);
});

it('removes the scans, events, artifacts and reports through the database cascade (AC-7)', function () {
    $submission = submissionWithEverything();
    $other = submissionWithEverything();

    $submission->delete();

    expect(Submission::query()->count())->toBe(1)
        ->and(Scan::query()->count())->toBe(1)
        ->and(ScanEvent::query()->count())->toBe(1)
        ->and(ScanArtifact::query()->count())->toBe(1)
        ->and(Report::query()->count())->toBe(1)
        ->and($other->fresh())->not->toBeNull();
});

it('deletes the rows and then the files on disk (AC-7)', function () {
    Storage::fake('local');
    $submission = submissionWithEverything();
    $scan = $submission->scans()->firstOrFail();

    Storage::disk('local')->put("submissions/{$submission->public_id}/manuscript.pdf", 'pdf');
    Storage::disk('local')->put("scans/{$scan->copyleaks_scan_id}/summary.json.gz", 'raw');
    Storage::disk('local')->put("reports/{$scan->id}/similarity-1-1.pdf", 'report');
    Storage::disk('local')->put('submissions/someone-else/manuscript.pdf', 'keep me');

    app(DeleteSubmission::class)->handle($submission);

    Storage::disk('local')->assertMissing("submissions/{$submission->public_id}/manuscript.pdf");
    Storage::disk('local')->assertMissing("scans/{$scan->copyleaks_scan_id}/summary.json.gz");
    Storage::disk('local')->assertMissing("reports/{$scan->id}/similarity-1-1.pdf");
    Storage::disk('local')->assertExists('submissions/someone-else/manuscript.pdf');
    expect(Submission::query()->count())->toBe(0);
});

it('deletes a submission that was never sent (AC-7)', function () {
    Storage::fake('local');
    $submission = Submission::factory()->create();
    Storage::disk('local')->put("submissions/{$submission->public_id}/manuscript.pdf", 'pdf');

    app(DeleteSubmission::class)->handle($submission);

    Storage::disk('local')->assertMissing("submissions/{$submission->public_id}/manuscript.pdf");
    expect(Submission::query()->count())->toBe(0);
});

it('refuses while the latest scan is still running and touches nothing (AC-7)', function (string $state) {
    Storage::fake('local');
    $submission = submissionWithEverything($state);
    Storage::disk('local')->put("submissions/{$submission->public_id}/manuscript.pdf", 'pdf');

    expect(fn () => app(DeleteSubmission::class)->handle($submission))
        ->toThrow(SubmissionHasRunningScan::class);

    Storage::disk('local')->assertExists("submissions/{$submission->public_id}/manuscript.pdf");
    expect(Submission::query()->count())->toBe(1)
        ->and(Scan::query()->count())->toBe(1);
})->with(['waiting', 'fetching']);

it('refuses while the latest scan is submitting (AC-7)', function () {
    $submission = Submission::factory()->create();
    Scan::factory()->for($submission)->create();

    app(DeleteSubmission::class)->handle($submission);
})->throws(SubmissionHasRunningScan::class);

it('allows delete when an earlier scan failed and the latest one is done (AC-7)', function () {
    Storage::fake('local');
    $submission = Submission::factory()->create();
    Scan::factory()->for($submission)->failed()->create();
    Scan::factory()->for($submission)->completed()->create();

    app(DeleteSubmission::class)->handle($submission);

    expect(Submission::query()->count())->toBe(0);
});
