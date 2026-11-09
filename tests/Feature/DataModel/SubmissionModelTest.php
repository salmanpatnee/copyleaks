<?php

use App\Enums\ArtifactKind;
use App\Enums\EventKind;
use App\Enums\ReportKind;
use App\Enums\ScanStatus;
use App\Enums\SubmissionStatus;
use App\Models\Report;
use App\Models\Scan;
use App\Models\ScanArtifact;
use App\Models\ScanEvent;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('saves a submission with its file facts and cover details and a ULID public id (AC-1)', function () {
    $submission = Submission::factory()->create();

    expect($submission->public_id)->toHaveLength(26)
        ->and($submission->getRouteKey())->toBe($submission->public_id)
        ->and($submission->getRouteKeyName())->toBe('public_id')
        ->and($submission->id)->toBeInt();
});

it('gives each new submission its own public id when none is supplied (AC-1)', function () {
    $attributes = Submission::factory()->make()->getAttributes();
    unset($attributes['public_id']);

    $a = Submission::query()->forceCreate($attributes);
    $b = Submission::query()->forceCreate([...$attributes, 'submission_number' => $attributes['submission_number'] + 1, 'file_sha256' => hash('sha256', 'other')]);

    expect($a->public_id)->toHaveLength(26)
        ->and($b->public_id)->toHaveLength(26)
        ->and($a->public_id)->not->toBe($b->public_id);
});

it('keeps the full tree together: submission, scan, artifacts, events and a report (AC-2)', function () {
    $submission = Submission::factory()->create();
    $scan = Scan::factory()->for($submission)->completed()->create();
    ScanArtifact::factory()->for($scan)->create(['kind' => ArtifactKind::Summary]);
    ScanArtifact::factory()->for($scan)->create(['kind' => ArtifactKind::Text]);
    ScanArtifact::factory()->for($scan)->create(['kind' => ArtifactKind::Result, 'source_id' => 'abc']);
    ScanEvent::factory()->for($scan)->create();
    Report::factory()->for($scan)->create();

    $scan = $scan->fresh();

    expect($submission->scans)->toHaveCount(1)
        ->and($scan->artifacts)->toHaveCount(3)
        ->and($scan->events)->toHaveCount(1)
        ->and($scan->reports)->toHaveCount(1)
        ->and($scan->submission->is($submission))->toBeTrue();
});

it('adds a new scan on retry and never a new submission, and the latest scan has the highest id (AC-2)', function () {
    $submission = Submission::factory()->create();
    $first = Scan::factory()->for($submission)->failed()->create();
    $second = Scan::factory()->for($submission)->waiting()->create();

    expect(Submission::query()->count())->toBe(1)
        ->and($submission->scans)->toHaveCount(2)
        ->and($first->copyleaks_scan_id)->not->toBe($second->copyleaks_scan_id)
        ->and($submission->latestScan->is($second))->toBeTrue();
});

it('works out the submission status from the latest scan, not from storage (AC-10)', function () {
    $notSent = Submission::factory()->create();
    expect($notSent->status)->toBe(SubmissionStatus::NotSent);

    $submission = Submission::factory()->create();
    Scan::factory()->for($submission)->failed()->create();
    expect($submission->fresh()->status)->toBe(SubmissionStatus::Failed);

    // A retry moves the status on, because the latest scan is the retry.
    Scan::factory()->for($submission)->waiting()->create();
    expect($submission->fresh()->status)->toBe(SubmissionStatus::Waiting);

    $done = Submission::factory()->create();
    Scan::factory()->for($done)->completed()->create();
    expect($done->fresh()->status)->toBe(SubmissionStatus::Done);

    expect(Schema::hasColumn('submissions', 'status'))->toBeFalse();
});

it('casts status and kind columns to enums (AC-3)', function () {
    $scan = Scan::factory()->create();
    $event = ScanEvent::factory()->for($scan)->create(['kind' => EventKind::ExportCompleted, 'status' => 'whatever']);
    $report = Report::factory()->for($scan)->create(['kind' => ReportKind::Ai]);

    expect($scan->fresh()->status)->toBe(ScanStatus::Submitting)
        ->and($event->fresh()->kind)->toBe(EventKind::ExportCompleted)
        ->and($event->fresh()->status)->toBe('whatever')
        ->and($report->fresh()->kind)->toBe(ReportKind::Ai);
});

it('keeps a failure reason on a failed scan (AC-3)', function () {
    $scan = Scan::factory()->failed('Copyleaks said no')->create();

    expect($scan->fresh()->failure_reason)->toBe('Copyleaks said no');
});

it('never exposes the webhook token hash when a scan is turned into an array', function () {
    $scan = Scan::factory()->create();

    expect($scan->toArray())->not->toHaveKey('webhook_token_hash');
});

it('lets a submission exist without a user and keeps it when the user is deleted (AC-9)', function () {
    $user = User::factory()->create();
    $submission = Submission::factory()->create(['user_id' => $user->id]);
    expect($user->submissions)->toHaveCount(1);

    $user->delete();

    expect($submission->fresh())->not->toBeNull()
        ->and($submission->fresh()->user_id)->toBeNull();
});

it('already has every column later slices need (AC-9)', function () {
    expect(Schema::hasColumns('submissions', ['user_id', 'word_count', 'character_count']))->toBeTrue()
        ->and(Schema::hasColumns('scans', ['similarity_percent', 'ai_percent', 'ai_requested']))->toBeTrue()
        ->and(ReportKind::Ai->value)->toBe('ai');
});
