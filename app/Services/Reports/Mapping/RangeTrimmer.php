<?php

namespace App\Services\Reports\Mapping;

/**
 * Cuts the ranges of one source to the length of our saved text (spec 0008, AC-15). Pure: numbers in, numbers out.
 * A start at or past the end is dropped. A range running past the end is clipped. Nothing is ever moved.
 */
class RangeTrimmer
{
    /**
     * @param  list<int>  $starts
     * @param  list<int>  $lengths  paired with `$starts` by position
     * @return array{starts: list<int>, lengths: list<int>, dropped: int, clipped: int, largestEnd: int}
     */
    public function trim(array $starts, array $lengths, int $textLength): array
    {
        $out = ['starts' => [], 'lengths' => [], 'dropped' => 0, 'clipped' => 0, 'largestEnd' => 0];

        foreach ($starts as $i => $start) {
            $length = $lengths[$i];
            $out['largestEnd'] = max($out['largestEnd'], $start + $length);

            if ($start >= $textLength) {
                $out['dropped']++;

                continue;
            }

            if ($length > $textLength - $start) {
                $length = $textLength - $start;
                $out['clipped']++;
            }

            $out['starts'][] = $start;
            $out['lengths'][] = $length;
        }

        return $out;
    }
}
