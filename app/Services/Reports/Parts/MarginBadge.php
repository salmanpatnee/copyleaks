<?php

namespace App\Services\Reports\Parts;

use App\Enums\MatchGroupStyle;

/** The margin badge: a group circle and a source number pill. x, y is the top left of the circle. */
final readonly class MarginBadge
{
    public function __construct(
        public float $x,
        public float $y,
        public MatchGroupStyle $style,
        public int $rank,
    ) {}
}
