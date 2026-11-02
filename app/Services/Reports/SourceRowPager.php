<?php

namespace App\Services\Reports;

/** Splits fixed height source rows over pages. A row is never split. */
final class SourceRowPager
{
    /** The divider sits 38 pt below the row's top (design spec, row anatomy). */
    private const DIVIDER_OFFSET = 38.0;

    /**
     * @return list<int> how many rows go on each page
     */
    public static function split(int $rowCount, float $firstListTop, float $laterListTop): array
    {
        $pages = [];
        $remaining = max(0, $rowCount);
        $top = $firstListTop;

        while ($remaining > 0) {
            $take = min($remaining, self::capacity($top));
            $pages[] = $take;
            $remaining -= $take;
            $top = $laterListTop;
        }

        return $pages;
    }

    public static function capacity(float $listTop): int
    {
        $room = ReportTheme::LIST_BOTTOM - $listTop - self::DIVIDER_OFFSET;

        return max(1, (int) floor($room / ReportTheme::ROW_H + 1e-9) + 1);
    }
}
