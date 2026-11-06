<?php

namespace App\Console\Commands;

use App\Enums\ScanStatus;
use App\Exceptions\ReportRenderFailed;
use App\Models\Submission;
use App\Services\Reports\Cover\CoverLeftOut;
use App\Services\Reports\Mapping\ReportDataMapper;
use App\Services\Reports\Overview\OverviewReportFactory;
use App\Services\Reports\ReportRenderer;
use App\Services\Reports\SampleFilePruner;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;

/** Builds a cover plus overview PDF from a saved scan. No Copyleaks call. Prints no author, title, assignment, source name or manuscript text. */
class BuildOverviewReport extends Command
{
    protected $signature = 'report:overview {public_id : Public ID of the submission}';

    protected $description = 'Build a cover and overview PDF from the newest completed scan of a submission';

    private const DIR = 'reports/samples';

    public function handle(
        ReportDataMapper $mapper,
        OverviewReportFactory $factory,
        ReportRenderer $renderer,
        CoverLeftOut $leftOut,
        SampleFilePruner $pruner,
        FilesystemFactory $files,
    ): int {
        $submission = Submission::query()->where('public_id', (string) $this->argument('public_id'))->first();
        $scan = $submission?->scans()->where('status', ScanStatus::Completed->value)->latest('id')->first();

        if ($scan === null) {
            $this->error('no_completed_scan: this submission has no completed scan.');

            return self::FAILURE;
        }

        try {
            $data = $mapper->map($scan);
            $built = $renderer->render($factory->make($data), self::DIR.'/overview-'.date('Ymd-His').'.pdf');
        } catch (ReportRenderFailed $e) {
            $this->error("{$e->reason}: {$e->getMessage()}");

            return self::FAILURE;
        }

        $pruner->prune(self::DIR, 'overview', (int) config('report.sample_keep', 5));

        $this->line('Path: '.$files->disk('local')->path($built->path));
        $this->line('Size: '.number_format($built->sizeBytes / 1024, 1).' KB');
        $this->line('Pages: '.$built->pageCount);
        $this->line('Sources: '.count($data->sources));
        $this->line('Left out: '.implode(', ', [...$leftOut->names($data->details), ...OverviewReportFactory::LEFT_OUT]));

        return self::SUCCESS;
    }
}
