<?php

namespace App\Services\Reports;

/** Points are used everywhere; only the canvas turns them into the millimetres mPDF wants. */
final class Pt
{
    public static function toMm(float $pt): float
    {
        return $pt * 25.4 / 72;
    }
}
