<?php

namespace App\Services\Reports\Highlights;

use App\Services\Reports\Manuscript\ManuscriptOutcome;
use App\Services\Reports\Mapping\ReportData;
use App\Services\Reports\ReportDocument;

/** The finished document with the plan, the manuscript outcome and the mapped data (for the command numbers). */
final readonly class SimilarityBuild
{
    public function __construct(
        public ReportDocument $document,
        public OverlayPlan $plan,
        public ManuscriptOutcome $outcome,
        public ReportData $data,
    ) {}
}
