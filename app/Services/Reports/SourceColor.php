<?php

namespace App\Services\Reports;

use InvalidArgumentException;

/** The four color source cycle. Rank 1 is magenta, then blue, green, purple, then it repeats. */
final readonly class SourceColor
{
    private function __construct(
        public string $strong,
        public string $tint,
    ) {}

    public static function forRank(int $rank): self
    {
        if ($rank < 1) {
            throw new InvalidArgumentException('Source ranks start at 1.');
        }

        $pair = ReportTheme::SOURCE_COLORS[($rank - 1) % 4];

        return new self($pair['strong'], $pair['tint']);
    }
}
