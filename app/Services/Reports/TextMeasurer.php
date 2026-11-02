<?php

namespace App\Services\Reports;

interface TextMeasurer
{
    /** Width in points of one line of text in Noto Sans (bold = SemiBold). */
    public function textWidth(string $text, float $size, bool $bold): float;
}
