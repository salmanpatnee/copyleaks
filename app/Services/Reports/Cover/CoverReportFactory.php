<?php

namespace App\Services\Reports\Cover;

use App\Enums\ReportKind;
use App\Exceptions\ReportRenderFailed;
use App\Services\Reports\Mapping\ReportData;
use App\Services\Reports\ReportAssets;
use App\Services\Reports\ReportDocument;

/** Mapped scan data to a one page cover document. Pure: no database, no Copyleaks, no disk, no log. */
final class CoverReportFactory
{
    public function __construct(private readonly ReportAssets $assets) {}

    /** @throws ReportRenderFailed when the scan has no submission ID */
    public function make(ReportData $data): ReportDocument
    {
        $id = trim((string) $data->details->submissionId);
        if ($id === '') {
            throw ReportRenderFailed::submissionIdMissing();
        }

        return new ReportDocument(ReportKind::Similarity, $id, [new CoverSection($data->details, $this->assets)]);
    }
}
