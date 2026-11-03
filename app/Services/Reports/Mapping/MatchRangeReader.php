<?php

namespace App\Services\Reports\Mapping;

use Illuminate\Support\Arr;

/** Reads the character ranges of the submitted text from one decoded result file. Keeps numbers only (AC-8). */
class MatchRangeReader
{
    private const KINDS = ['identical', 'minorChanges', 'relatedMeaning'];

    /**
     * @param  array<mixed>  $result
     * @return array{starts: list<int>, lengths: list<int>} valid pairs, before merging (flat int lists keep memory small)
     */
    public function read(array $result): array
    {
        $out = ['starts' => [], 'lengths' => []];

        foreach (self::KINDS as $kind) {
            $starts = Arr::get($result, "text.comparison.{$kind}.suspected.chars.starts");
            $lengths = Arr::get($result, "text.comparison.{$kind}.suspected.chars.lengths");

            if (! is_array($starts) || ! is_array($lengths)) {
                continue;
            }

            $pairs = min(count($starts), count($lengths));

            for ($i = 0; $i < $pairs; $i++) {
                $start = WholeNumber::from($starts[$i] ?? null);
                $length = WholeNumber::from($lengths[$i] ?? null);

                if ($start !== null && $length !== null && $start >= 0 && $length >= 1) {
                    $out['starts'][] = $start;
                    $out['lengths'][] = $length;
                }
            }
        }

        return $out;
    }
}
