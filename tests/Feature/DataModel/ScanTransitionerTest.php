<?php

use App\Enums\ScanStatus;
use App\Models\Scan;
use App\Services\ScanTransitioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->transitioner = new ScanTransitioner;
});

it('moves submitting to waiting and sets submitted_at in the same update (AC-11)', function () {
    $scan = Scan::factory()->create();

    expect($scan->submitted_at)->toBeNull();

    $moved = $this->transitioner->markWaiting($scan);

    expect($moved)->toBeTrue()
        ->and($scan->status)->toBe(ScanStatus::Waiting)
        ->and($scan->submitted_at)->not->toBeNull();
});

it('lets a scan skip waiting when the completion arrives first (AC-11)', function () {
    $scan = Scan::factory()->create();

    expect($this->transitioner->markFetching($scan))->toBeTrue()
        ->and($scan->status)->toBe(ScanStatus::Fetching);
});

it('moves fetching to completed and sets completed_at (AC-11)', function () {
    $scan = Scan::factory()->fetching()->create();

    expect($this->transitioner->markCompleted($scan))->toBeTrue()
        ->and($scan->status)->toBe(ScanStatus::Completed)
        ->and($scan->completed_at)->not->toBeNull();
});

it('fails a running scan from any running state and keeps the reason (AC-3, AC-11)', function (string $state) {
    $scan = Scan::factory()->{$state}()->create();

    expect($this->transitioner->markFailed($scan, 'E100 timeout'))->toBeTrue()
        ->and($scan->status)->toBe(ScanStatus::Failed)
        ->and($scan->failure_reason)->toBe('E100 timeout');
})->with(['waiting', 'fetching']);

it('fails a scan that is still submitting (AC-11)', function () {
    $scan = Scan::factory()->create();

    expect($this->transitioner->markFailed($scan, 'could not reach Copyleaks'))->toBeTrue()
        ->and($scan->status)->toBe(ScanStatus::Failed);
});

it('never moves a completed scan again (AC-11)', function () {
    $scan = Scan::factory()->completed()->create();

    expect($this->transitioner->markFetching($scan))->toBeFalse()
        ->and($this->transitioner->markFailed($scan, 'late error'))->toBeFalse()
        ->and($scan->fresh()->status)->toBe(ScanStatus::Completed)
        ->and($scan->fresh()->failure_reason)->toBeNull();
});

it('never moves a failed scan again (AC-11)', function () {
    $scan = Scan::factory()->failed('first reason')->create();

    expect($this->transitioner->markWaiting($scan))->toBeFalse()
        ->and($this->transitioner->markCompleted($scan))->toBeFalse()
        ->and($scan->fresh()->status)->toBe(ScanStatus::Failed)
        ->and($scan->fresh()->failure_reason)->toBe('first reason');
});

it('refuses a move that is not in the table, such as waiting back to submitting (AC-11)', function () {
    $scan = Scan::factory()->waiting()->create();

    expect($this->transitioner->move($scan, ScanStatus::Submitting))->toBeFalse()
        ->and($this->transitioner->move($scan, ScanStatus::Completed))->toBeFalse()
        ->and($scan->fresh()->status)->toBe(ScanStatus::Waiting);
});

it('skips quietly when a second job holds a stale copy of the scan (AC-11)', function () {
    $scan = Scan::factory()->create();
    $staleCopy = Scan::query()->findOrFail($scan->id);

    // Job A wins the race.
    expect($this->transitioner->markFetching($scan))->toBeTrue();

    // Job B still thinks the scan is `submitting` and tries to mark it waiting.
    expect($staleCopy->status)->toBe(ScanStatus::Submitting)
        ->and($this->transitioner->markWaiting($staleCopy))->toBeFalse()
        ->and($staleCopy->status)->toBe(ScanStatus::Fetching)
        ->and($scan->fresh()->status)->toBe(ScanStatus::Fetching)
        ->and($scan->fresh()->submitted_at)->toBeNull();
});

it('does not let a fillable mass update change the status', function () {
    $scan = Scan::factory()->create();

    $scan->update(['status' => 'completed']);

    expect($scan->fresh()->status)->toBe(ScanStatus::Submitting);
});
