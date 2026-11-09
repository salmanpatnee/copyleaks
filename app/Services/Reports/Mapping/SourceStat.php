<?php

namespace App\Services\Reports\Mapping;

/** The numbers behind one listed source, for the check command and for tests. */
final readonly class SourceStat
{
    public function __construct(
        public int $rank,
        public int $matchedWords,
        public int $rangeCount,
        public bool $hasId,
        public int $droppedRanges = 0,
        public int $clippedRanges = 0,
    ) {}
}
