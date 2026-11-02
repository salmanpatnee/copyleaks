<?php

namespace App\Services\Reports;

/** Whole numbers, and `<1%` for anything above 0 and below 1. */
final class PercentFormat
{
    public static function label(float $percent): string
    {
        if ($percent > 0 && $percent < 1) {
            return '<1%';
        }

        return (int) round($percent).'%';
    }
}
