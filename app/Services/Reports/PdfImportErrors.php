<?php

namespace App\Services\Reports;

use App\Exceptions\ReportRenderFailed;
use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use Throwable;

/** Turns a PDF import error into a ReportRenderFailed with a plain reason (no file content in it). */
final class PdfImportErrors
{
    public static function map(Throwable $e): ReportRenderFailed
    {
        if ($e instanceof CrossReferenceException) {
            return match ($e->getCode()) {
                CrossReferenceException::COMPRESSED_XREF => ReportRenderFailed::unsupportedPdf(),
                CrossReferenceException::ENCRYPTED => ReportRenderFailed::encryptedPdf(),
                default => ReportRenderFailed::unreadablePdf(),
            };
        }

        return $e instanceof ReportRenderFailed ? $e : ReportRenderFailed::unreadablePdf();
    }
}
