<?php

namespace App\Services\Reports\Highlights;

use App\Exceptions\ReportRenderFailed;
use App\Services\Reports\Manuscript\ManuscriptPages;
use App\Services\Reports\Mapping\ReportData;
use App\Services\Reports\Overview\OverviewReportFactory;
use App\Services\Reports\ReportDocument;

/** Data, manuscript pages and overlays to the full similarity document. Writes nothing, no database, no Copyleaks, no log. */
final class SimilarityReportFactory
{
    public function __construct(private readonly OverviewReportFactory $overview) {}

    /** @throws ReportRenderFailed */
    public function make(ReportData $data, ManuscriptPages $pages, OverlayPlan $plan): ReportDocument
    {
        $overview = $this->overview->make($data);

        return new ReportDocument($overview->kind, $overview->headerId, [
            ...$overview->sections,
            $pages->section('Integrity Submission', $plan->highlights, $plan->badges),
        ]);
    }
}
