<?php

namespace App\Services\Reports\Highlights;

use App\Services\Reports\Parts\MarginBadge;

/**
 * Gives each badge a left margin slot. A badge never moves up or down, only right into the
 * next free slot. When all four slots are busy the badge is dropped. Pure: no disk, no log.
 */
final class BadgeSlotter
{
    public const SLOT_FIRST_X = 7.5;

    public const SLOT_STEP = 36.5;

    public const SLOT_COUNT = 4;

    /** Badge height 15.5 plus a 1 pt gap. */
    public const MIN_GAP = 16.5;

    /**
     * @param  list<BadgeCandidate>  $candidates
     * @return array{badges: array<int, list<MarginBadge>>, dropped: int}
     */
    public function place(array $candidates): array
    {
        usort($candidates, static fn (BadgeCandidate $a, BadgeCandidate $b): int => [$a->page, $a->y, $a->start] <=> [$b->page, $b->y, $b->start]);

        $badges = [];
        $lastY = [];
        $dropped = 0;

        foreach ($candidates as $candidate) {
            $slot = $this->freeSlot($lastY[$candidate->page] ?? [], $candidate->y);
            if ($slot === null) {
                $dropped++;

                continue;
            }

            $lastY[$candidate->page][$slot] = $candidate->y;
            $badges[$candidate->page][] = new MarginBadge(self::SLOT_FIRST_X + self::SLOT_STEP * $slot, $candidate->y, $candidate->style, $candidate->rank);
        }

        return ['badges' => $badges, 'dropped' => $dropped];
    }

    /** @param  array<int, float>  $lastY  the top of the last badge in each used slot of this page */
    private function freeSlot(array $lastY, float $y): ?int
    {
        for ($slot = 0; $slot < self::SLOT_COUNT; $slot++) {
            if (! isset($lastY[$slot]) || $y >= $lastY[$slot] + self::MIN_GAP) {
                return $slot;
            }
        }

        return null;
    }
}
