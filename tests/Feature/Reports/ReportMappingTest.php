<?php

use App\Enums\ScanStatus;
use App\Exceptions\ReportRenderFailed;
use App\Models\Scan;
use App\Models\ScanArtifact;
use App\Services\Copyleaks\RawFileWriter;
use App\Services\Reports\Mapping\ReportDataMapper;
use App\Services\Reports\Mapping\SourceNamer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

require_once __DIR__.'/../../Support/CopyleaksTestHelpers.php';

uses(TestCase::class, RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

function saveArtifact(Scan $scan, string $kind, string $sourceId, string $raw): ScanArtifact
{
    $name = $kind.($sourceId === '' ? '' : '-'.$sourceId);
    $file = app(RawFileWriter::class)->store("scans/{$scan->copyleaks_scan_id}/{$name}.json.gz", $raw);

    return ScanArtifact::query()->create([
        'scan_id' => $scan->id, 'kind' => $kind, 'source_id' => $sourceId,
        'path' => $file['path'], 'sha256' => $file['sha256'], 'size_bytes' => $file['size'],
    ]);
}

/** @return array<string, mixed> */
function matchesAt(array $pairs): array
{
    return ['text' => ['comparison' => ['identical' => ['suspected' => ['chars' => [
        'starts' => array_column($pairs, 0), 'lengths' => array_column($pairs, 1),
    ]]]]]];
}

/**
 * A completed scan with a saved summary. `$groups` maps group to items; `$results` maps source id to a result body (null = no row).
 *
 * @param  array<string, list<array<string, mixed>>>  $groups
 * @param  array<string, array<string, mixed>>  $results
 */
function completedScan(array $groups = [], array $results = [], int $totalWords = 1000, float|string|null $score = 12.456, array $submission = []): Scan
{
    $scan = scanFor(pdfSubmission($submission), ScanStatus::Completed);
    $summary = [
        'scannedDocument' => ['scanId' => $scan->copyleaks_scan_id, 'totalWords' => $totalWords],
        'results' => ['score' => ['aggregatedScore' => $score], ...$groups],
    ];
    saveArtifact($scan, 'summary', '', json_encode($summary));

    foreach ($results as $id => $body) {
        saveArtifact($scan, 'result', $id, json_encode($body));
    }

    return $scan->refresh();
}

function mapper(): ReportDataMapper
{
    return app(ReportDataMapper::class);
}

function item(string $id, int $words, array $extra = []): array
{
    return ['id' => $id, 'matchedWords' => $words, 'url' => "https://www.{$id}.test/page", ...$extra];
}

it('maps score, details, ranked sources and merged ranges (AC-1, 3, 4, 5, 11)', function () {
    $scan = completedScan(
        ['internet' => [item('a', 100), item('b', 300)], 'database' => [item('c', 100), item('zero', 0)]],
        ['a' => matchesAt([[0, 10]]), 'b' => matchesAt([[5, 10], [50, 5]]), 'c' => matchesAt([[100, 4]])],
        submission: ['author' => 'Ann', 'title' => '  ', 'assignment' => 'Essay', 'original_name' => 'paper.pdf'],
    );

    $data = mapper()->map($scan);

    expect($data)->toEqual(mapper()->map($scan))
        ->and($data->similarityPercent)->toBe(12.46)
        ->and((float) $scan->similarity_percent)->not->toBe(99.0)
        ->and($data->totalWords)->toBe(1000)
        ->and(array_map(fn ($r) => [$r->rank, $r->name, $r->percent], $data->sources))
        ->toBe([[1, 'www.b.test', 30.0], [2, 'www.a.test', 10.0], [3, 'www.c.test', 10.0]])
        ->and($data->sources[0]->category)->toBe('Internet')
        ->and($data->sources[2]->category)->toBeNull()
        ->and($data->details->author)->toBe('Ann')
        ->and($data->details->title)->toBeNull()
        ->and($data->details->fileName)->toBe('paper.pdf')
        ->and($data->details->submissionId)->toBe($scan->copyleaks_scan_id)
        ->and($data->details->words)->toBe(1000)
        ->and($data->details->pages)->toBeNull();

    // a (rank 2) 0..10 and b (rank 1) 5..15 overlap, 50..55 alone, c 100..104 alone.
    expect(array_map(fn ($r) => [$r->start, $r->length, $r->rank], $data->ranges))
        ->toBe([[0, 15, 2], [50, 5, 1], [100, 4, 3]]);
});

it('keeps touching ranges apart and takes the best rank at the first character (AC-9)', function () {
    $scan = completedScan(
        ['internet' => [item('a', 50), item('b', 100)]],
        ['a' => matchesAt([[0, 10], [10, 5]]), 'b' => matchesAt([[3, 2], [12, 10]])],
    );

    $ranges = mapper()->map($scan)->ranges;

    // b is rank 1, a is rank 2. [0,10) a only; [3,5) inside it. [10,15) a, [12,22) b overlap, starts under a.
    expect(array_map(fn ($r) => [$r->start, $r->length, $r->rank], $ranges))->toBe([[0, 10, 2], [10, 12, 2]]);
});

it('skips odd range data but keeps the source (AC-8)', function () {
    $scan = completedScan(
        ['internet' => [item('a', 10), item('b', 9), item('c', 8)]],
        [
            'a' => matchesAt([[-1, 5], [4, 0], ['x', 3], [7, 3]]),
            'b' => ['text' => ['comparison' => ['identical' => ['suspected' => ['chars' => ['starts' => [1, 2, 3], 'lengths' => [4]]]]]]],
            'c' => ['text' => []],
        ],
    );

    $data = mapper()->map($scan);

    expect(array_map(fn ($s) => $s->rangeCount, $data->sourceStats))->toBe([1, 1, 0])
        ->and($data->sources)->toHaveCount(3);
});

it('names sources from url host, then title, then a fixed text (AC-7)', function () {
    $namer = new SourceNamer;

    expect($namer->name(['url' => 'https://www.example.org/a?b=1', 'title' => 'T']))->toBe('www.example.org')
        ->and($namer->name(['url' => 'not a url', 'title' => "  A\x07 title "]))->toBe('A title')
        ->and($namer->name(['title' => 'Only title']))->toBe('Only title')
        ->and($namer->name(['url' => 7, 'title' => '   ']))->toBe('Untitled source');
});

it('lists a source with no id and no ranges, and drops repeated ids (AC-4)', function () {
    $scan = completedScan(['internet' => [['matchedWords' => 5, 'title' => 'No id'], item('a', 4), item('a', 99)]], ['a' => matchesAt([[0, 1]])]);

    $data = mapper()->map($scan);

    expect($data->sources)->toHaveCount(2)
        ->and($data->sourceStats[0]->hasId)->toBeFalse()
        ->and($data->sourceStats[1]->matchedWords)->toBe(4);
});

it('caps percent at 100 (AC-4)', function () {
    $scan = completedScan(['internet' => [item('a', 5000)]], ['a' => matchesAt([])], totalWords: 1000);

    expect(mapper()->map($scan)->sources[0]->percent)->toBe(100.0);
});

it('stops with a fixed reason for each failure (AC-1, 2, 3, 4)', function (callable $make, string $reason) {
    expect(fn () => mapper()->map($make()))->toThrow(fn (ReportRenderFailed $e) => $e->reason === $reason);
})->with([
    'not completed' => [fn () => scanFor(pdfSubmission(), ScanStatus::Fetching), 'scan_not_completed'],
    'no summary row' => [fn () => scanFor(pdfSubmission(), ScanStatus::Completed), 'summary_missing'],
    'no score' => [fn () => completedScan(score: null), 'summary_unreadable'],
    'text score' => [fn () => completedScan(score: 'high'), 'summary_unreadable'],
    'zero words' => [fn () => completedScan(totalWords: 0), 'summary_unreadable'],
    'bad matched words' => [fn () => completedScan(['internet' => [item('a', 1, ['matchedWords' => 'many'])]]), 'summary_unreadable'],
    'result row missing' => [fn () => completedScan(['internet' => [item('a', 3)]]), 'result_missing'],
    'summary file gone' => [function () {
        $scan = completedScan();
        Storage::disk('local')->delete($scan->artifacts()->first()->path);

        return $scan;
    }, 'summary_missing'],
    'result file gone' => [function () {
        $scan = completedScan(['internet' => [item('a', 3)]], ['a' => matchesAt([])]);
        Storage::disk('local')->delete($scan->artifacts()->where('kind', 'result')->first()->path);

        return $scan;
    }, 'result_missing'],
    'wrong checksum' => [function () {
        $scan = completedScan();
        $scan->artifacts()->update(['sha256' => str_repeat('0', 64)]);

        return $scan;
    }, 'checksum_mismatch'],
    'bad gzip' => [function () {
        $scan = scanFor(pdfSubmission(), ScanStatus::Completed);
        Storage::disk('local')->put('x/summary.gz', 'not gzip');
        ScanArtifact::query()->create(['scan_id' => $scan->id, 'kind' => 'summary', 'source_id' => '', 'path' => 'x/summary.gz', 'sha256' => hash('sha256', 'not gzip'), 'size_bytes' => 8]);

        return $scan->refresh();
    }, 'summary_unreadable'],
    'bad json in result' => [function () {
        $scan = completedScan(['internet' => [item('a', 3)]]);
        saveArtifact($scan, 'result', 'a', '{broken');

        return $scan->refresh();
    }, 'result_unreadable'],
]);

it('makes no Copyleaks call and no HTTP request (AC-1, AC-14)', function () {
    Http::fake();
    $scan = completedScan(['internet' => [item('a', 3)]], ['a' => matchesAt([[0, 3]])]);
    $before = serialize(fakeScanClient());

    mapper()->map($scan);

    expect(serialize(fakeScanClient()))->toBe($before);
    Http::assertNothingSent();
});

it('keeps the mapping folder free of logs, clients and database writes (AC-13)', function () {
    $banned = ['Log::', 'ScanClient', 'Http::', '::create(', '->save(', '->update(', '->delete(', 'DB::', 'SubmitScan'];

    foreach (glob(app_path('Services/Reports/Mapping/*.php')) as $file) {
        $code = file_get_contents($file);
        foreach ($banned as $word) {
            expect($code)->not->toContain($word, basename($file));
        }
    }
});

it('maps 300 sources under 128 MB (AC-14)', function () {
    $items = [];
    $results = [];
    for ($i = 1; $i <= 300; $i++) {
        $items[] = item("s{$i}", $i);
        $results["s{$i}"] = matchesAt([[$i * 10, 5]]);
    }
    $scan = completedScan(['internet' => $items], $results, totalWords: 100000);

    $data = mapper()->map($scan);

    expect($data->sources)->toHaveCount(300)
        ->and(memory_get_peak_usage(true))->toBeLessThan(128 * 1024 * 1024);
});

it('maps one big result file under 128 MB (AC-14)', function () {
    $count = 300000;
    $starts = range(0, $count * 20 - 1, 20);
    $body = matchesAt([]);
    $body['text']['comparison']['identical']['suspected']['chars'] = ['starts' => $starts, 'lengths' => array_fill(0, $count, 5)];
    $body['padding'] = array_fill(0, 100000, 'x');
    $scan = completedScan(['internet' => [item('big', 5)]], ['big' => $body]);
    unset($body, $starts);
    gc_collect_cycles();
    memory_reset_peak_usage();
    $baseline = memory_get_usage();

    $data = mapper()->map($scan);

    expect($data->sourceStats[0]->rangeCount)->toBe($count)
        ->and(memory_get_peak_usage() - $baseline)->toBeLessThan(128 * 1024 * 1024); // what the mapping itself adds, the test app is not counted
});

describe('report:mapping', function () {
    it('prints the numbers, warns on zero ranges and prints no text (AC-12)', function () {
        $scan = completedScan(['internet' => [item('a', 100), item('b', 50)]], ['a' => matchesAt([[0, 10]]), 'b' => matchesAt([])]);

        $this->artisan('report:mapping', ['public_id' => $scan->submission->public_id])
            ->expectsOutputToContain('Score: 12.46%')
            ->expectsOutputToContain('Sources: 2')
            ->expectsOutputToContain('#1 [Internet] www.a.test')
            ->expectsOutputToContain('warning: source #2 has matched words but 0 ranges.')
            ->expectsOutputToContain('Merged ranges: 1')
            ->expectsOutputToContain('Largest merged range: 10 characters')
            ->assertSuccessful();
    });

    it('shows a stop reason and fails (AC-12)', function () {
        $scan = completedScan(totalWords: 0);

        $this->artisan('report:mapping', ['public_id' => $scan->submission->public_id])
            ->expectsOutputToContain('summary_unreadable')
            ->assertFailed();
    });

    it('says no_completed_scan for an unknown id (AC-12)', function () {
        $this->artisan('report:mapping', ['public_id' => 'nope'])
            ->expectsOutputToContain('no_completed_scan')
            ->assertFailed();
    });
});
