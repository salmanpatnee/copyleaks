<?php

use App\Actions\SubmitScan;
use App\Jobs\SubmitScanJob;
use App\Models\Scan;
use App\Services\Copyleaks\CopyleaksScanClient;
use App\Services\Copyleaks\FakeScanClient;
use App\Services\Copyleaks\ScanClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

require_once __DIR__.'/../../Support/CopyleaksTestHelpers.php';

// Money safety (AC-13): nothing but the Submit action may queue a scan.
arch('only the Submit action queues the submit job')
    ->expect(SubmitScanJob::class)
    ->toOnlyBeUsedIn(SubmitScan::class);

arch('only the real client talks to Copyleaks over HTTP')
    ->expect('Illuminate\Support\Facades\Http')
    ->toOnlyBeUsedIn(CopyleaksScanClient::class);

arch('the real client is only built by the container binding')
    ->expect(CopyleaksScanClient::class)
    ->toOnlyBeUsedIn('App\Providers\AppServiceProvider');

arch('callers depend on the ScanClient interface, never on the real client')
    ->expect('App\Actions')->not->toUse(CopyleaksScanClient::class);

it('has no other place that dispatches the submit job', function () {
    $hits = collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS)))
        ->filter(fn (SplFileInfo $file) => $file->getExtension() === 'php')
        ->filter(fn (SplFileInfo $file) => str_contains((string) file_get_contents($file->getPathname()), 'SubmitScanJob::dispatch'))
        ->map(fn (SplFileInfo $file) => basename($file->getPathname()))
        ->values()
        ->all();

    expect($hits)->toBe(['SubmitScan.php']);
});

it('has no scheduler entry that could start a scan', function () {
    expect(file_get_contents(base_path('routes/console.php')))->not->toContain('SubmitScan');
});

it('binds the fake client in the testing environment (AC-13)', function () {
    expect(app(ScanClient::class))->toBe(app(FakeScanClient::class));
});

describe('with a database', function () {
    uses(RefreshDatabase::class);

    beforeEach(fn () => Storage::fake('local'));

    it('creates no scan when a file is uploaded (AC-13)', function () {
        $file = UploadedFile::fake()->createWithContent('one.pdf', file_get_contents(base_path('tests/Fixtures/one-page.pdf')));

        $this->post(route('submissions.store'), ['file' => $file, 'author' => 'A', 'title' => 'T', 'assignment' => 'C'])->assertRedirect();

        expect(Scan::count())->toBe(0)->and(fakeScanClient()->submitted)->toBeEmpty();
    });

    it('creates no scan from factories', function () {
        Scan::factory()->create();

        expect(fakeScanClient()->submitted)->toBeEmpty();
    });
});
