<?php

namespace App\Services\Reports\Mapping;

use Carbon\CarbonInterface;

/** One date form for the cover, always in UTC: `Oct 28, 2026, 4:12 PM GMT+0` (spec 0012, AC-5). */
class ReportDateFormatter
{
    public function format(CarbonInterface $date): string
    {
        return $date->copy()->utc()->locale('en')->format('M j, Y, g:i A').' GMT+0';
    }
}
