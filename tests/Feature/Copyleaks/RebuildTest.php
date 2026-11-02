<?php

use App\Jobs\DeleteRemoteScanJob;
use App\Models\ScanArtifact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once __DIR__.'/../../Support/CopyleaksTestHelpers.php';

beforeEach(function () {
    Storage::fake('local');
    copyleaksReady();
    Queue::fake([DeleteRemoteScanJob::class]);
});

it('lets a report be rebuilt from our own files with no new Copyleaks call (AC-15)', function () {
    $scan = fetchingScan();
    runExport($scan);
    pushEverything($this, $scan);
    $callsBefore = fakeScanClient()->callCount();

    $artifacts = ScanArtifact::where('scan_id', $scan->id)->get();

    expect($artifacts->map(fn (ScanArtifact $a) => $a->kind->value)->sort()->values()->all())->toBe(['result', 'result', 'summary', 'text']);
    foreach ($artifacts as $artifact) {
        $bytes = Storage::disk('local')->get($artifact->path);
        expect(hash('sha256', $bytes))->toBe($artifact->sha256)
            ->and(strlen($bytes))->toBe($artifact->size_bytes)
            ->and(json_validate((string) gzdecode($bytes)))->toBeTrue();
    }
    expect(fakeScanClient()->callCount())->toBe($callsBefore);
});
