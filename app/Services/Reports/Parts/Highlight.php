<?php

namespace App\Services\Reports\Parts;

use App\Enums\MatchGroupStyle;

/** A text highlight. Points are measured from the top left of the page as shown (after any rotation). */
final readonly class Highlight
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public MatchGroupStyle $style,
    ) {}
}
