<?php

namespace App\Services\Reports\Parts;

use App\Enums\MatchGroupStyle;

/** One match group line: count and percent (the style gives color, icon and description). */
final readonly class MatchGroupRow
{
    public function __construct(
        public MatchGroupStyle $style,
        public int $count,
        public float $percent,
    ) {}
}
