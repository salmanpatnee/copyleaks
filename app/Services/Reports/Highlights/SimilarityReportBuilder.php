<?php

namespace App\Services\Reports\Highlights;

use App\Exceptions\ReportRenderFailed;
use App\Models\Scan;
use App\Services\Reports\Manuscript\ManuscriptPagesBuilder;
use App\Services\Reports\Mapping\ReportDataMapper;

/** Completed scan to similarity document. The first failure wins and passes through unchanged. Never calls Copyleaks. */
final class SimilarityReportBuilder
{
    public function __construct(
        private readonly ReportDataMapper $mapper,
        private readonly ManuscriptPagesBuilder $pagesBuilder,
        private readonly HighlightPlacer $placer,
        private readonly SimilarityReportFactory $factory,
    ) {}

    /** @throws ReportRenderFailed */
    public function build(Scan $scan): SimilarityBuild
    {
        $data = $this->mapper->map($scan);
        $pages = $this->pagesBuilder->build($scan);
        $plan = $this->placer->place($data->ranges, $pages, $data->highlightStyle);

        return new SimilarityBuild($this->factory->make($data, $pages, $plan), $plan, $pages->outcome(), $data);
    }
}
