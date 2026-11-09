<?php

namespace App\Console\Commands;

use App\Enums\ReportKind;
use App\Enums\ScanStatus;
use App\Exceptions\ReportRenderFailed;
use App\Models\Submission;
use App\Services\Reports\Manuscript\ManuscriptPagesBuilder;
use App\Services\Reports\Mapping\ReportIdFormatter;
use App\Services\Reports\ReportDocument;
use App\Services\Reports\ReportRenderer;
use Illuminate\Console\Command;

/** Builds the manuscript pages of the latest completed scan and a check PDF with an outline around every word box. */
class CheckManuscriptPages extends Command
{
    protected $signature = 'report:manuscript
        {public_id : Public ID of the submission}
        {--output= : Path on the local disk (default reports/checks/{public_id}-manuscript.pdf)}';

    protected $description = 'Check how a manuscript is matched to its pages (mode, match share, outlined check PDF)';

    public function handle(ManuscriptPagesBuilder $builder, ReportRenderer $renderer, ReportIdFormatter $ids): int
    {
        $submission = Submission::query()->where('public_id', (string) $this->argument('public_id'))->first();
        $scan = $submission?->scans()->where('status', ScanStatus::Completed->value)->latest('id')->first();

        if ($scan === null) {
            $this->error('no_completed_scan: this submission has no completed scan.');

            return self::FAILURE;
        }

        $custom = (string) $this->option('output');
        $target = $custom !== '' ? $custom : 'reports/checks/'.$submission->public_id.'-manuscript.pdf';

        try {
            $pages = $builder->build($scan);
            $built = $renderer->render(
                new ReportDocument(ReportKind::Similarity, $ids->format((int) $submission->submission_number), [$pages->checkSection()]),
                $target,
            );
        } catch (ReportRenderFailed $e) {
            $this->error("{$e->reason}: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->line('Mode: '.$pages->mode->value);
        $this->line('Reason: '.($pages->fallbackReason->value ?? 'none'));
        if ($pages->measured !== null) {
            $this->line('Match share: '.number_format($pages->measured->overallShare * 100, 2).'%');
            foreach ($pages->measured->pageShares as $page => $share) {
                $this->line("  page {$page}: ".number_format($share * 100, 2).'%');
            }
        }
        $this->line('Pages: '.$pages->pageCount());
        $this->line('Check PDF: '.storage_path('app/private/'.$built->path));

        return self::SUCCESS;
    }
}
