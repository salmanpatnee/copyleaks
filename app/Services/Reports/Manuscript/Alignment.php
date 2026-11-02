<?php

namespace App\Services\Reports\Manuscript;

/**
 * Which Copyleaks offsets were found on which PDF word, and how much was found.
 * A found offset maps to `[pdf page index (0 based), word index on that page]`.
 */
final readonly class Alignment
{
    /**
     * @param  array<int, array{0: int, 1: int}>  $found  Copyleaks offset => [page index, word index]
     * @param  list<int>  $cleanedOffsets  sorted offsets of every letter or digit (found or not)
     * @param  array<int, float>  $pageShares  PDF page number (from 1) => share of its letters and digits that matched; blank and short pages are left out
     */
    public function __construct(
        public array $found,
        public array $cleanedOffsets,
        public float $overallShare,
        public array $pageShares,
    ) {}

    public function minPageShare(): float
    {
        return $this->pageShares === [] ? 1.0 : min($this->pageShares);
    }
}
