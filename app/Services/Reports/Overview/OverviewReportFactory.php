<?php

namespace App\Services\Reports\Overview;

use App\Enums\ReportKind;
use App\Exceptions\ReportRenderFailed;
use App\Services\Reports\Cover\CoverReportFactory;
use App\Services\Reports\Mapping\ReportData;
use App\Services\Reports\ReportAssets;
use App\Services\Reports\ReportDocument;

/** Mapped scan data to a cover plus overview document. Pure: no database, no Copyleaks, no disk, no log. */
final class OverviewReportFactory
{
    /** Overview blocks the real report leaves out today (no Copyleaks data yet), in the order they are printed. */
    public const LEFT_OUT = ['Match Groups', 'Source totals', 'Filtered list', 'Integrity flag count'];

    public function __construct(
        private readonly CoverReportFactory $cover,
        private readonly ReportAssets $assets,
    ) {}

    /** @throws ReportRenderFailed when the scan has no submission ID */
    public function make(ReportData $data): ReportDocument
    {
        $cover = $this->cover->make($data);

        return new ReportDocument(ReportKind::Similarity, $cover->headerId, [
            ...$cover->sections,
            new OverviewSection($data->similarityPercent, $data->sources, $this->assets),
        ]);
    }
}
