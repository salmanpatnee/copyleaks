<?php

namespace App\Services\Reports\Mapping;

/** Merges overlapping ranges from all sources into highlight ranges with the best rank (AC-9). */
class RangeMerger
{
    /**
     * Three lists of the same length, one entry per source range.
     *
     * @param  list<int>  $starts
     * @param  list<int>  $lengths
     * @param  list<int>  $ranks
     * @return list<HighlightRange> sorted by start
     */
    public function merge(array $starts, array $lengths, array $ranks): array
    {
        array_multisort($starts, SORT_ASC, $ranks, SORT_ASC, $lengths);

        $merged = [];
        $start = $end = $rank = null;

        foreach ($starts as $i => $rangeStart) {
            $rangeEnd = $rangeStart + $lengths[$i];

            if ($start !== null && $rangeStart < $end) {
                $end = max($end, $rangeEnd);

                continue;
            }

            if ($start !== null) {
                $merged[] = new HighlightRange($start, $end - $start, $rank);
            }

            // Sorted by start then rank: the first range of a cluster has the best rank among those covering its first character.
            [$start, $end, $rank] = [$rangeStart, $rangeEnd, $ranks[$i]];
        }

        if ($start !== null) {
            $merged[] = new HighlightRange($start, $end - $start, $rank);
        }

        return $merged;
    }
}
