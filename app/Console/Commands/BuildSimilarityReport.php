<?php

namespace App\Console\Commands;

use App\Enums\ManuscriptMode;
use App\Enums\ScanStatus;
use App\Exceptions\ReportRenderFailed;
use App\Models\Submission;
use App\Services\Reports\Cover\CoverLeftOut;
use App\Services\Reports\Highlights\SimilarityReportBuilder;
use App\Services\Reports\Overview\OverviewReportFactory;
use App\Services\Reports\ReportRenderer;
use App\Services\Reports\SampleFilePruner;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;

/** Builds the full similarity PDF (cover, overview, highlighted manuscript) from a saved scan. No Copyleaks call. Prints numbers only: no author, title, assignment, source name or manuscript text. */
class BuildSimilarityReport extends Command
{
    protected $signature = 'report:similarity {public_id : Public ID of the submission}';

    protected $description = 'Build the full similarity PDF from the newest completed scan of a submission';

    private const DIR = 'reports/samples';

    public function handle(
        SimilarityReportBuilder $builder,
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
            $build = $builder->build($scan);
            $built = $renderer->render($build->document, self::DIR.'/similarity-'.date('Ymd-His').'.pdf');
        } catch (ReportRenderFailed $e) {
            $this->error("{$e->reason}: {$e->getMessage()}");

            return self::FAILURE;
        }

        $pruner->prune(self::DIR, 'similarity', (int) config('report.sample_keep', 5));

        $plan = $build->plan;
        $mode = $build->outcome->mode === ManuscriptMode::Original
            ? 'original'
            : 'text ('.$build->outcome->reason?->value.')';

        $this->line('Path: '.$files->disk('local')->path($built->path));
        $this->line('Size: '.number_format($built->sizeBytes / 1024, 1).' KB');
        $this->line('Pages: '.$built->pageCount);
        $this->line('Sources: '.count($build->data->sources));
        $this->line('Manuscript: '.$mode);
        $this->line('Ranges: '.count($build->data->ranges));
        $this->line('Highlights: '.$plan->highlightCount().' rectangles');
        $this->line('Badges: '.$plan->badgeCount());
        $this->line('Unplaced ranges: '.$plan->unplacedRanges);
        $this->line('Dropped badges: '.$plan->droppedBadges);
        $this->line('Left out: '.implode(', ', [...$leftOut->names($build->data->details), ...OverviewReportFactory::LEFT_OUT]));

        return self::SUCCESS;
    }
}
