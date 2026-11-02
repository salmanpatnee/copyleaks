<?php

namespace App\Services\Reports\Manuscript;

/** One word on a page: its rectangle in points from the top left, and its cleaned letters and digits. */
final readonly class WordBox
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public string $chars,
    ) {}
}
