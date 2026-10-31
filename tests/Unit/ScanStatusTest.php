<?php

use App\Enums\ScanStatus;
use App\Enums\SubmissionStatus;

it('allows only the moves in the spec table', function (ScanStatus $from, ScanStatus $to, bool $allowed) {
    expect($from->canMoveTo($to))->toBe($allowed);
})->with([
    'submitting to waiting' => [ScanStatus::Submitting, ScanStatus::Waiting, true],
    'submitting to fetching' => [ScanStatus::Submitting, ScanStatus::Fetching, true],
    'submitting to failed' => [ScanStatus::Submitting, ScanStatus::Failed, true],
    'submitting to completed' => [ScanStatus::Submitting, ScanStatus::Completed, false],
    'waiting to fetching' => [ScanStatus::Waiting, ScanStatus::Fetching, true],
    'waiting to failed' => [ScanStatus::Waiting, ScanStatus::Failed, true],
    'waiting back to submitting' => [ScanStatus::Waiting, ScanStatus::Submitting, false],
    'waiting to completed' => [ScanStatus::Waiting, ScanStatus::Completed, false],
    'fetching to completed' => [ScanStatus::Fetching, ScanStatus::Completed, true],
    'fetching to failed' => [ScanStatus::Fetching, ScanStatus::Failed, true],
    'fetching back to waiting' => [ScanStatus::Fetching, ScanStatus::Waiting, false],
    'completed to fetching' => [ScanStatus::Completed, ScanStatus::Fetching, false],
    'completed to failed' => [ScanStatus::Completed, ScanStatus::Failed, false],
    'failed to waiting' => [ScanStatus::Failed, ScanStatus::Waiting, false],
]);

it('treats completed and failed as terminal', function () {
    expect(ScanStatus::Completed->isTerminal())->toBeTrue()
        ->and(ScanStatus::Failed->isTerminal())->toBeTrue()
        ->and(ScanStatus::Submitting->isRunning())->toBeTrue()
        ->and(ScanStatus::Waiting->isRunning())->toBeTrue()
        ->and(ScanStatus::Fetching->isRunning())->toBeTrue();
});

it('lists the states a scan may move to the target from', function () {
    expect(ScanStatus::Fetching->allowedFrom())->toBe([ScanStatus::Submitting, ScanStatus::Waiting])
        ->and(ScanStatus::Submitting->allowedFrom())->toBe([])
        ->and(ScanStatus::Failed->allowedFrom())->toBe([
            ScanStatus::Submitting, ScanStatus::Waiting, ScanStatus::Fetching,
        ]);
});

it('works out the submission status from the latest scan', function (?ScanStatus $scan, SubmissionStatus $expected) {
    expect(SubmissionStatus::fromScan($scan))->toBe($expected);
})->with([
    'no scan' => [null, SubmissionStatus::NotSent],
    'submitting' => [ScanStatus::Submitting, SubmissionStatus::Waiting],
    'waiting' => [ScanStatus::Waiting, SubmissionStatus::Waiting],
    'fetching' => [ScanStatus::Fetching, SubmissionStatus::Waiting],
    'completed' => [ScanStatus::Completed, SubmissionStatus::Done],
    'failed' => [ScanStatus::Failed, SubmissionStatus::Failed],
]);
