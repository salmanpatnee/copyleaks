<?php

namespace App\Services\Reports\Mapping;

use App\Enums\MatchGroupStyle;
use App\Services\Reports\Parts\DocumentDetails;
use App\Services\Reports\Parts\SourceRow;

/**
 * Everything the cover, overview and highlight pages need from one saved scan (spec 0008).
 * It has no field for match groups, source totals, integrity flags, the filtered list or AI: those spots are left out.
 */
final readonly class ReportData
{
    /**
     * @param  list<SourceRow>  $sources  ranked 1 to n
     * @param  list<SourceStat>  $sourceStats  same order as `$sources`
     * @param  list<HighlightRange>  $ranges  merged, sorted by start
     */
    public function __construct(
        public float $similarityPercent,
        public int $totalWords,
        public DocumentDetails $details,
        public array $sources,
        public array $sourceStats,
        public array $ranges,
        public MatchGroupStyle $highlightStyle = MatchGroupStyle::NotCitedOrQuoted,
    ) {}
}
