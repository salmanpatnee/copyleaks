<?php

namespace App\Services\Reports\Highlights;

use App\Services\Reports\Parts\Highlight;
use App\Services\Reports\Parts\MarginBadge;

/** Highlights and badges for the manuscript pages, keyed by page number, plus counts. Numbers only, no text. */
final readonly class OverlayPlan
{
    /**
     * @param  array<int, list<Highlight>>  $highlights
     * @param  array<int, list<MarginBadge>>  $badges
     */
    public function __construct(
        public array $highlights,
        public array $badges,
        public int $unplacedRanges,
        public int $droppedBadges,
    ) {}

    public function highlightCount(): int
    {
        return array_sum(array_map('count', $this->highlights));
    }

    public function badgeCount(): int
    {
        return array_sum(array_map('count', $this->badges));
    }
}
