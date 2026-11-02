<?php

namespace App\Services\Reports\Manuscript;

/** One word to draw on a text page. `top` is the top of the line box, in points from the top of the page. */
final readonly class TextRun
{
    public function __construct(
        public float $x,
        public float $top,
        public string $text,
    ) {}
}
