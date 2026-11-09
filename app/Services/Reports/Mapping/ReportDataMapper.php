<?php

namespace App\Services\Reports\Mapping;

use App\Enums\ScanStatus;
use App\Exceptions\ReportRenderFailed;
use App\Models\Scan;
use App\Services\Copyleaks\ScanSummary;
use App\Services\Reports\Manuscript\CrawledTextReader;
use App\Services\Reports\Parts\SourceRow;

/**
 * The one way in: a completed scan to typed report data, from our saved files only (spec 0008, AC-1).
 * It never calls Copyleaks and never writes. A damaged or missing file stops the whole mapping.
 */
class ReportDataMapper
{
    public function __construct(
        private ScanBundleReader $bundle,
        private SourceListBuilder $sourceList,
        private MatchRangeReader $rangeReader,
        private RangeMerger $merger,
        private DetailsMapper $details,
        private CrawledTextReader $textReader,
        private RangeTrimmer $trimmer,
    ) {}

    public function map(Scan $scan): ReportData
    {
        if ($scan->status !== ScanStatus::Completed) {
            throw ReportRenderFailed::scanNotCompleted();
        }

        $summary = new ScanSummary($this->bundle->summary($scan));

        $similarity = $summary->similarityPercent();
        $totalWords = $summary->totalWords();

        if ($similarity === null || $totalWords === null || $totalWords <= 0) {
            throw ReportRenderFailed::summaryUnreadable();
        }

        // Only the length of the text is kept, never the text itself (AC-15).
        $textLength = ($this->textReader->read($scan) ?? throw ReportRenderFailed::crawledTextMissing())->length();

        $sources = $this->sourceList->build($summary, $totalWords);
        unset($summary);

        $starts = $lengths = $ranks = [];
        $stats = [];
        $largestEnd = 0;

        foreach ($sources as $source) {
            $count = $dropped = $clipped = 0;

            if ($source->id !== null) {
                $found = $this->trimmer->trim(
                    ...$this->rangeReader->read($this->bundle->result($scan, $source->id)),
                    textLength: $textLength,
                );
                $dropped = $found['dropped'];
                $clipped = $found['clipped'];
                $largestEnd = max($largestEnd, $found['largestEnd']);
                $count = count($found['starts']);

                foreach ($found['starts'] as $i => $start) {
                    $starts[] = $start;
                    $lengths[] = $found['lengths'][$i];
                    $ranks[] = $source->rank;
                }
                unset($found);
            }

            $stats[] = new SourceStat($source->rank, $source->matchedWords, $count, $source->id !== null, $dropped, $clipped);
        }

        return new ReportData(
            similarityPercent: $similarity,
            totalWords: $totalWords,
            details: $this->details->map($scan, $totalWords),
            sources: array_map(fn (ListedSource $s): SourceRow => new SourceRow($s->rank, $s->category, $s->name, $s->percent), $sources),
            sourceStats: $stats,
            ranges: $this->merger->merge($starts, $lengths, $ranks),
            largestRangeEnd: $largestEnd,
            textLength: $textLength,
        );
    }
}
