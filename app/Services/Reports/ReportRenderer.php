<?php

namespace App\Services\Reports;

use App\Exceptions\ReportRenderFailed;
use App\Services\BuiltReport;

/** Callers depend on this, never on mPDF. */
interface ReportRenderer
{
    /**
     * @param  string  $targetPath  relative to the `local` disk; BuiltReport::path is the same string
     *
     * @throws ReportRenderFailed
     */
    public function render(ReportDocument $document, string $targetPath): BuiltReport;
}
