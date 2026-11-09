<?php

namespace App\Services\Reports\Mapping;

/** File size with 1024 as the step and one decimal: `812 B`, `113.9 KB`, `1.0 MB` (spec 0012, AC-6). */
class FileSizeFormatter
{
    private const UNITS = ['KB', 'MB', 'GB'];

    public function format(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }

        $value = $bytes / 1024;
        $unit = 0;

        // A value that would round to 1024.0 moves up to the next unit.
        while (round($value, 1) >= 1024 && $unit < count(self::UNITS) - 1) {
            $value /= 1024;
            $unit++;
        }

        return number_format($value, 1, '.', '').' '.self::UNITS[$unit];
    }
}
