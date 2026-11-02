<?php

namespace App\Services\Reports;

use App\Exceptions\ReportRenderFailed;
use Mpdf\Container\SimpleContainer;
use Mpdf\Mpdf;
use Throwable;

/**
 * Measures text before any report is drawn (the text page layout needs it to know the page count).
 * It uses the same font setup as the renderer, so a width measured here is the width drawn later,
 * fallback fonts included.
 */
class MpdfTextMeasurer implements TextMeasurer
{
    private ?Mpdf $mpdf = null;

    public function __construct(private readonly ReportFonts $fonts) {}

    public function textWidth(string $text, float $size, bool $bold): float
    {
        $mpdf = $this->mpdf();
        $mpdf->SetFont(ReportFonts::NOTO, $bold ? 'B' : '', $size);

        return $mpdf->GetStringWidth($text) * 72 / 25.4;
    }

    private function mpdf(): Mpdf
    {
        if ($this->mpdf !== null) {
            return $this->mpdf;
        }

        $tempDir = (string) config('report.temp_dir');
        if (! is_dir($tempDir) && ! @mkdir($tempDir, 0775, true) && ! is_dir($tempDir)) {
            throw ReportRenderFailed::writeFailed();
        }

        try {
            return $this->mpdf = new Mpdf([
                'mode' => 'utf-8',
                'tempDir' => $tempDir,
                ...$this->fonts->config(),
            ], new SimpleContainer(['httpClient' => new NullHttpClient]));
        } catch (Throwable) {
            throw ReportRenderFailed::failed();
        }
    }
}
