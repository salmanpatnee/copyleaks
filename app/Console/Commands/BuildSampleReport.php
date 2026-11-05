<?php

namespace App\Console\Commands;

use App\Exceptions\ReportRenderFailed;
use App\Services\Reports\ReportRenderer;
use App\Services\Reports\Sample\SampleReportFactory;
use App\Services\Reports\SampleFilePruner;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;

/** Builds one sample PDF from made up data so the layout can be checked by eye. No Copyleaks call, no database rows. */
class BuildSampleReport extends Command
{
    protected $signature = 'report:sample
        {--pages=3 : Pages of the made up manuscript}
        {--sources=30 : Source rows in the overview}
        {--import=* : Paths of real PDFs to import instead of the made up manuscript}
        {--output= : Path on the local disk (default reports/samples/sample-{Ymd-His}.pdf)}';

    protected $description = 'Build a sample report PDF from made up data';

    private const SAMPLE_DIR = 'reports/samples';

    public function handle(ReportRenderer $renderer, SampleReportFactory $factory, FilesystemFactory $files, SampleFilePruner $pruner): int
    {
        $custom = (string) $this->option('output');
        $target = $custom !== '' ? $custom : self::SAMPLE_DIR.'/sample-'.date('Ymd-His').'.pdf';
        $imports = array_values(array_filter((array) $this->option('import'), 'is_string'));
        $started = microtime(true);
        $sample = null;

        try {
            $sample = $factory->make(max(1, (int) $this->option('pages')), max(0, (int) $this->option('sources')), $imports);
            $built = $renderer->render($sample->document, $target);
        } catch (ReportRenderFailed $e) {
            $this->error("Sample failed: {$e->reason}. {$e->getMessage()}");

            return self::FAILURE;
        } finally {
            foreach ($sample->tempFiles ?? [] as $file) {
                @unlink($file);
            }
        }

        if ($custom === '') {
            $pruner->prune(self::SAMPLE_DIR, 'sample', (int) config('report.sample_keep', 5));
        }

        $this->line('Path: '.$files->disk('local')->path($built->path));
        $this->line('Size: '.number_format($built->sizeBytes / 1024, 1).' KB');
        $this->line('Pages: '.$built->pageCount);
        $this->line('Build time: '.number_format(microtime(true) - $started, 1).' s');
        $this->line('Peak memory: '.number_format(memory_get_peak_usage(true) / 1048576, 1).' MB');

        return self::SUCCESS;
    }
}
