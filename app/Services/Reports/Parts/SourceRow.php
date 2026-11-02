<?php

namespace App\Services\Reports\Parts;

/** One line of the Top Sources list. */
final readonly class SourceRow
{
    public function __construct(
        public int $rank,
        public string $category,
        public string $name,
        public float $percent,
    ) {}
}
