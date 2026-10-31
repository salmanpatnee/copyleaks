<?php

use App\Enums\ArtifactKind;
use App\Enums\EventKind;
use App\Enums\ReportKind;
use App\Models\Report;
use App\Models\Scan;
use App\Models\ScanArtifact;
use App\Models\ScanEvent;
use App\Models\Submission;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('rejects a duplicate copyleaks scan id (AC-3)', function () {
    Scan::factory()->create(['copyleaks_scan_id' => 'same-id']);

    Scan::factory()->create(['copyleaks_scan_id' => 'same-id']);
})->throws(QueryException::class);

it('rejects the same webhook delivery twice, so repeats are safe (AC-4)', function () {
    $scan = Scan::factory()->create();
    ScanEvent::factory()->for($scan)->create(['kind' => EventKind::Status, 'status' => 'completed']);

    ScanEvent::factory()->for($scan)->create(['kind' => EventKind::Status, 'status' => 'completed']);
})->throws(QueryException::class);

it('records the same status under another kind or another scan (AC-4)', function () {
    $scan = Scan::factory()->create();
    ScanEvent::factory()->for($scan)->create(['kind' => EventKind::Status, 'status' => 'completed']);
    ScanEvent::factory()->for($scan)->create(['kind' => EventKind::ExportCompleted, 'status' => 'completed']);
    ScanEvent::factory()->create(['kind' => EventKind::Status, 'status' => 'completed']);

    expect(ScanEvent::query()->count())->toBe(3);
});

it('ignores a repeated delivery when saved with insertOrIgnore (AC-4)', function () {
    $scan = Scan::factory()->create();
    $row = [
        'scan_id' => $scan->id,
        'kind' => 'status',
        'status' => 'completed',
        'payload' => '{}',
        'received_at' => now(),
    ];

    $first = ScanEvent::query()->insertOrIgnore($row);
    $second = ScanEvent::query()->insertOrIgnore($row);

    expect($first)->toBe(1)
        ->and($second)->toBe(0)
        ->and(ScanEvent::query()->count())->toBe(1);
});

it('rejects a second summary artifact for the same scan (AC-5)', function () {
    $scan = Scan::factory()->create();
    ScanArtifact::factory()->for($scan)->create(['kind' => ArtifactKind::Summary]);

    ScanArtifact::factory()->for($scan)->create(['kind' => ArtifactKind::Summary]);
})->throws(QueryException::class);

it('allows many result artifacts when each has its own source id (AC-5)', function () {
    $scan = Scan::factory()->create();
    ScanArtifact::factory()->for($scan)->create(['kind' => ArtifactKind::Result, 'source_id' => 'a']);
    ScanArtifact::factory()->for($scan)->create(['kind' => ArtifactKind::Result, 'source_id' => 'b']);

    expect($scan->artifacts()->count())->toBe(2);
});

it('rejects a repeated result artifact for the same source id (AC-5)', function () {
    $scan = Scan::factory()->create();
    ScanArtifact::factory()->for($scan)->create(['kind' => ArtifactKind::Result, 'source_id' => 'a']);

    ScanArtifact::factory()->for($scan)->create(['kind' => ArtifactKind::Result, 'source_id' => 'a']);
})->throws(QueryException::class);

it('has no updated_at on artifacts and offers no update path (AC-5)', function () {
    $artifact = ScanArtifact::factory()->create();

    expect(Schema::hasColumn('scan_artifacts', 'updated_at'))->toBeFalse()
        ->and($artifact->created_at)->not->toBeNull();

    $artifact->path = 'somewhere/else';
    $artifact->save();
})->throws(LogicException::class, 'insert only');

it('leaves the stored artifact unchanged after a blocked update (AC-5)', function () {
    $artifact = ScanArtifact::factory()->create(['path' => 'scans/x/summary.json.gz']);

    try {
        $artifact->update(['path' => 'changed']);
    } catch (LogicException) {
        // expected
    }

    expect($artifact->fresh()->path)->toBe('scans/x/summary.json.gz');
});

it('rejects a second report of the same kind for a scan (AC-6)', function () {
    $scan = Scan::factory()->create();
    Report::factory()->for($scan)->create(['kind' => ReportKind::Similarity]);

    Report::factory()->for($scan)->create(['kind' => ReportKind::Similarity]);
})->throws(QueryException::class);

it('allows a similarity and an ai report on the same scan, with renderer version and page count (AC-6)', function () {
    $scan = Scan::factory()->create();
    Report::factory()->for($scan)->create(['kind' => ReportKind::Similarity]);
    $ai = Report::factory()->for($scan)->create(['kind' => ReportKind::Ai, 'renderer_version' => '3', 'page_count' => 7]);

    expect($scan->reports)->toHaveCount(2)
        ->and($ai->fresh()->renderer_version)->toBe('3')
        ->and($ai->fresh()->page_count)->toBe(7);
});

it('allows two submissions with the same file checksum so staff can rescan on purpose (AC-8)', function () {
    $sha = hash('sha256', 'same file');
    Submission::factory()->create(['file_sha256' => $sha]);
    Submission::factory()->create(['file_sha256' => $sha]);

    expect(Submission::query()->where('file_sha256', $sha)->count())->toBe(2);
});

it('rejects a submission whose cover details are missing (AC-1)', function () {
    Submission::factory()->create(['author' => null]);
})->throws(QueryException::class);
