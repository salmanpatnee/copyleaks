<?php

namespace App\Services\Reports\Mapping;

/** One merged stretch of matched text, in Copyleaks character offsets, with the rank of its best source. */
final readonly class HighlightRange
{
    public function __construct(
        public int $start,
        public int $length,
        public int $rank,
    ) {}
}
