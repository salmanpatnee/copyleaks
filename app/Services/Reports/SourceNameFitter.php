<?php

namespace App\Services\Reports;

/** Cuts a source name with "…" so it ends before the name limit (x = 358). */
final class SourceNameFitter
{
    public function __construct(private readonly TextMeasurer $measurer) {}

    public function fit(string $name): string
    {
        $limit = ReportTheme::NAME_LIMIT_X - ReportTheme::MARGIN_X;

        if ($this->width($name) <= $limit) {
            return $name;
        }

        $length = mb_strlen($name);
        while ($length > 0) {
            $length--;
            $candidate = rtrim(mb_substr($name, 0, $length)).'…';
            if ($this->width($candidate) <= $limit) {
                return $candidate;
            }
        }

        return '…';
    }

    private function width(string $text): float
    {
        return $this->measurer->textWidth($text, ReportTheme::SIZE_SOURCE, true);
    }
}
