<?php

use App\Enums\ScanStatus;
use App\Jobs\DeleteRemoteScanJob;
use App\Services\Copyleaks\ScanClientUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once __DIR__.'/../../Support/CopyleaksTestHelpers.php';

beforeEach(function () {
    Storage::fake('local');
    copyleaksReady();
});

function runDelete(int $scanId): void
{
    app()->call([new DeleteRemoteScanJob($scanId), 'handle']);
}

it('tries only once (AC-12)', function () {
    expect((new DeleteRemoteScanJob(1))->tries)->toBe(1);
});

it('deletes a completed scan once and records when (AC-12)', function () {
    $scan = scanFor(pdfSubmission(), ScanStatus::Completed);

    runDelete($scan->id);
    runDelete($scan->id);

    expect(fakeScanClient()->deleted)->toBe([$scan->copyleaks_scan_id])->and($scan->refresh()->remote_deleted_at)->not->toBeNull();
});

it('keeps the scan completed and writes one warning when Copyleaks refuses (AC-12)', function () {
    Log::spy();
    $scan = scanFor(pdfSubmission(), ScanStatus::Completed);
    fakeScanClient()->willDelete(false);

    runDelete($scan->id);

    $scan->refresh();
    expect($scan->status)->toBe(ScanStatus::Completed)->and($scan->remote_deleted_at)->toBeNull();
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $m, array $c) => $c === ['scan_id' => $scan->copyleaks_scan_id]);
});

it('treats an unreachable Copyleaks as a failed delete, not an error (AC-12)', function () {
    $scan = scanFor(pdfSubmission(), ScanStatus::Completed);
    fakeScanClient()->willDelete(new ScanClientUnavailable('timeout'));

    runDelete($scan->id);

    expect($scan->refresh()->status)->toBe(ScanStatus::Completed)->and($scan->remote_deleted_at)->toBeNull();
});

it('never calls Copyleaks for a scan that is not completed (AC-12)', function (ScanStatus $status) {
    $scan = scanFor(pdfSubmission(), $status);

    runDelete($scan->id);

    expect(fakeScanClient()->deleted)->toBeEmpty()->and($scan->refresh()->remote_deleted_at)->toBeNull();
})->with([ScanStatus::Fetching, ScanStatus::Failed, ScanStatus::Waiting]);

it('does nothing for a scan that no longer exists', function () {
    runDelete(999999);

    expect(fakeScanClient()->deleted)->toBeEmpty();
});
