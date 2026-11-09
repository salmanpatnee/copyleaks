<?php

namespace App\Services\Reports\Highlights;

use App\Enums\MatchGroupStyle;

/** A badge that wants a spot: the page, the top of its match's first line, the range start (for ordering) and its rank. */
final readonly class BadgeCandidate
{
    public function __construct(
        public int $page,
        public float $y,
        public int $start,
        public int $rank,
        public MatchGroupStyle $style,
    ) {}
}
