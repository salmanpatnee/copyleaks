<?php

use App\Enums\ReportKind;
use App\Models\Report;
use App\Models\Scan;
use App\Services\BuiltReport;
use App\Services\ReportReplacer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function builtReport(string $path, string $version = '2', int $pages = 5): BuiltReport
{
    Storage::disk('local')->put($path, 'pdf bytes '.$path);

    return new BuiltReport($path, hash('sha256', $path), 123, $pages, $version);
}

beforeEach(function () {
    Storage::fake('local');
    $this->scan = Scan::factory()->completed()->create();
    $this->replacer = app(ReportReplacer::class);
});

it('creates the first report for a scan (AC-6)', function () {
    $report = $this->replacer->replace(
        $this->scan,
        ReportKind::Similarity,
        fn () => builtReport('reports/1/similarity-1-a.pdf', '1', 8),
    );

    expect($report->path)->toBe('reports/1/similarity-1-a.pdf')
        ->and($report->renderer_version)->toBe('1')
        ->and($report->page_count)->toBe(8)
        ->and($this->scan->reports()->count())->toBe(1);
});

it('replaces the row and deletes the old file only after the new one is saved (AC-6, AC-12)', function () {
    $this->replacer->replace($this->scan, ReportKind::Similarity, fn () => builtReport('reports/1/old.pdf', '1'));

    $this->replacer->replace($this->scan, ReportKind::Similarity, fn () => builtReport('reports/1/new.pdf', '2', 9));

    $row = Report::query()->sole();
    expect($row->path)->toBe('reports/1/new.pdf')
        ->and($row->renderer_version)->toBe('2')
        ->and($row->page_count)->toBe(9);
    Storage::disk('local')->assertExists('reports/1/new.pdf');
    Storage::disk('local')->assertMissing('reports/1/old.pdf');
});

it('leaves the old report row and file untouched when the build throws (AC-12)', function () {
    $this->replacer->replace($this->scan, ReportKind::Similarity, fn () => builtReport('reports/1/old.pdf', '1'));
    $before = Report::query()->sole();

    expect(fn () => $this->replacer->replace(
        $this->scan,
        ReportKind::Similarity,
        function () {
            Storage::disk('local')->put('reports/1/half-written.pdf', 'partial');

            throw new RuntimeException('engine crashed');
        },
    ))->toThrow(RuntimeException::class, 'engine crashed');

    $after = Report::query()->sole();
    expect($after->path)->toBe($before->path)
        ->and($after->sha256)->toBe($before->sha256)
        ->and($after->renderer_version)->toBe('1');
    Storage::disk('local')->assertExists('reports/1/old.pdf');
});

it('removes the new file and keeps the old report when switching the row fails (AC-12)', function () {
    $this->replacer->replace($this->scan, ReportKind::Similarity, fn () => builtReport('reports/1/old.pdf', '1'));

    // A scan id that does not exist breaks the foreign key, so the row switch fails after the file is written.
    $ghost = new Scan;
    $ghost->id = 999_999;

    expect(fn () => $this->replacer->replace(
        $ghost,
        ReportKind::Similarity,
        fn () => builtReport('reports/999999/new.pdf'),
    ))->toThrow(Exception::class);

    Storage::disk('local')->assertMissing('reports/999999/new.pdf');
    Storage::disk('local')->assertExists('reports/1/old.pdf');
    expect(Report::query()->sole()->path)->toBe('reports/1/old.pdf');
});

it('keeps a similarity and an ai report side by side (AC-6)', function () {
    $this->replacer->replace($this->scan, ReportKind::Similarity, fn () => builtReport('reports/1/s.pdf'));
    $this->replacer->replace($this->scan, ReportKind::Ai, fn () => builtReport('reports/1/a.pdf'));

    expect($this->scan->reports()->count())->toBe(2);
    Storage::disk('local')->assertExists('reports/1/s.pdf');
    Storage::disk('local')->assertExists('reports/1/a.pdf');
});
