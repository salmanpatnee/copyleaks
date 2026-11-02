<?php

namespace App\Services\Reports\Manuscript;

/** One rectangle in points from the top left of a manuscript page. `page` starts at 1 inside the manuscript section. */
final readonly class LineBox
{
    public function __construct(
        public int $page,
        public float $x,
        public float $y,
        public float $width,
        public float $height,
    ) {}
}
