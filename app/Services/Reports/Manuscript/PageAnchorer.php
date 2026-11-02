<?php

namespace App\Services\Reports\Manuscript;

/**
 * Finds where each PDF page starts inside the cleaned Copyleaks text (AC-13). Copyleaks page starts
 * are not used. Pages are searched in order and only forward, so a repeated page cannot pull an
 * anchor backwards.
 */
class PageAnchorer
{
    private const PROBE = 24;

    private const TRIES = 4;

    private const MIN_CHARS = 8;

    private const REACH_PAGES = 3;

    /**
     * @param  list<list<string>>  $pages  cleaned characters of every PDF page, page 1 first
     * @return array{anchors: array<int, int>, short: list<int>} page index => cleaned index where it starts; and the
     *                                                           pages too short to have a probe (they join the previous region)
     */
    public function anchor(CleanedText $copyleaks, array $pages): array
    {
        $prefix = $this->sharedPrefix($pages);
        $reach = (int) ceil(self::REACH_PAGES * $copyleaks->length() / max(1, count($pages)));

        $anchors = [];
        $short = [];
        $last = null;
        $lastLength = 0;

        foreach ($pages as $index => $chars) {
            $length = count($chars);
            if ($length === 0) {
                continue;
            }
            if ($length < self::MIN_CHARS) {
                $short[] = $index;

                continue;
            }

            $from = $last === null ? 0 : $last + max(1, intdiv($lastLength, 2));
            $skip = $length - $prefix >= self::MIN_CHARS ? $prefix : 0;
            $hit = $this->probe($copyleaks, $chars, $skip, $from, $from + $reach + self::PROBE);

            if ($hit !== null) {
                $anchors[$index] = $hit;
                $last = $hit;
                $lastLength = $length;
            }
        }

        return ['anchors' => $anchors, 'short' => $short];
    }

    /** @param  list<string>  $chars */
    private function probe(CleanedText $copyleaks, array $chars, int $skip, int $from, int $until): ?int
    {
        for ($try = 0; $try < self::TRIES; $try++) {
            $shift = $skip + $try * self::PROBE;
            $part = array_slice($chars, $shift, self::PROBE);
            if (count($part) < self::MIN_CHARS) {
                break;
            }

            $hit = $copyleaks->find(implode('', $part), $from + $shift, $until + $shift);
            if ($hit !== null) {
                return $hit - $shift;
            }
        }

        return null;
    }

    /**
     * Start text that every page shares (a running header). Cut off before probing, so a repeated
     * header cannot anchor a page onto the next page.
     *
     * @param  list<list<string>>  $pages
     */
    private function sharedPrefix(array $pages): int
    {
        $usable = array_values(array_filter($pages, static fn (array $p): bool => count($p) >= self::MIN_CHARS));
        if (count($usable) < 2) {
            return 0;
        }

        $first = $usable[0];
        $length = min(array_map('count', $usable));
        $common = 0;
        while ($common < $length && $this->allEqualAt($usable, $common, $first[$common])) {
            $common++;
        }

        return $common;
    }

    /** @param  list<list<string>>  $pages */
    private function allEqualAt(array $pages, int $position, string $character): bool
    {
        foreach ($pages as $page) {
            if ($page[$position] !== $character) {
                return false;
            }
        }

        return true;
    }
}
