<?php

namespace App\Services\Reports\Manuscript;

use App\Enums\ManuscriptFallbackReason;
use App\Enums\ManuscriptMode;
use App\Services\Reports\Parts\Highlight;
use App\Services\Reports\Parts\MarginBadge;
use App\Services\Reports\ReportSection;
use Closure;

/**
 * The manuscript as report pages, plus the answer to "where are characters 120 to 340?".
 * One mode for the whole manuscript, never a mix of original and text pages.
 */
final readonly class ManuscriptPages
{
    /**
     * @param  list<PdfPageWords>  $pages  word boxes per manuscript page, page 1 first
     * @param  Alignment|null  $measured  the match that was measured against the PDF pages (null when no matching ran). In `text` mode after `low_match` it holds the shares that failed
     * @param  Closure(string, array<int, list<Highlight>>, array<int, list<MarginBadge>>, array<int, list<LineBox>>): ReportSection  $sectionFactory
     */
    public function __construct(
        public ManuscriptMode $mode,
        public ?ManuscriptFallbackReason $fallbackReason,
        private array $pages,
        public Alignment $alignment,
        private Closure $sectionFactory,
        public ?Alignment $measured = null,
    ) {}

    public function pageCount(): int
    {
        return count($this->pages);
    }

    public function outcome(): ManuscriptOutcome
    {
        return new ManuscriptOutcome($this->mode, $this->fallbackReason);
    }

    /**
     * @param  array<int, list<Highlight>>  $highlights  keyed by page number, starting at 1
     * @param  array<int, list<MarginBadge>>  $badges  keyed by page number, starting at 1
     */
    public function section(string $label = 'Integrity Submission', array $highlights = [], array $badges = []): ReportSection
    {
        return ($this->sectionFactory)($label, $highlights, $badges, []);
    }

    /** The same pages with a thin outline around every word box, for checking positions by eye. */
    public function checkSection(string $label = 'Manuscript check'): ReportSection
    {
        $outlines = [];
        foreach ($this->pages as $index => $page) {
            $outlines[$index + 1] = array_map(
                static fn (WordBox $w): LineBox => new LineBox($index + 1, $w->x, $w->y, $w->width, $w->height),
                $page->words,
            );
        }

        return ($this->sectionFactory)($label, [], [], $outlines);
    }

    /**
     * Rectangles for a range of Copyleaks offsets. Words of the range on one line join into one
     * box; a new box starts at each new line and page. Offsets that were not found are skipped.
     * An offset on a character the clean up removed (a space, punctuation) adds nothing. An empty
     * or out of range request gives an empty list.
     *
     * @return list<LineBox>
     */
    public function lineBoxes(int $start, int $length): array
    {
        $offsets = $this->alignment->cleanedOffsets;
        if ($length <= 0 || $start < 0 || $offsets === []) {
            return [];
        }

        $end = $start + $length;
        $words = [];
        for ($i = $this->firstIndexAtOrAfter($offsets, $start); $i < count($offsets) && $offsets[$i] < $end; $i++) {
            $this->remember($words, $offsets[$i]);
        }

        return $this->join($words);
    }

    /** @param  array<string, array{0: int, 1: int}>  $words */
    private function remember(array &$words, int $offset): void
    {
        $at = $this->alignment->found[$offset] ?? null;
        if ($at !== null) {
            $words[$at[0].':'.$at[1]] = $at;
        }
    }

    /**
     * @param  array<string, array{0: int, 1: int}>  $words  distinct words in order
     * @return list<LineBox>
     */
    private function join(array $words): array
    {
        $boxes = [];
        $current = null;
        $lineTop = 0.0;
        $lineHeight = 0.0;

        foreach ($words as [$pageIndex, $wordIndex]) {
            $word = $this->pages[$pageIndex]->words[$wordIndex] ?? null;
            if ($word === null) {
                continue;
            }

            $sameLine = $current !== null
                && $current['page'] === $pageIndex + 1
                && abs($word->y - $lineTop) <= $lineHeight / 2;

            if ($sameLine) {
                $current['left'] = min($current['left'], $word->x);
                $current['right'] = max($current['right'], $word->x + $word->width);
                $current['top'] = min($current['top'], $word->y);
                $current['bottom'] = max($current['bottom'], $word->y + $word->height);

                continue;
            }

            if ($current !== null) {
                $boxes[] = $this->box($current);
            }
            $current = ['page' => $pageIndex + 1, 'left' => $word->x, 'right' => $word->x + $word->width, 'top' => $word->y, 'bottom' => $word->y + $word->height];
            $lineTop = $word->y;
            $lineHeight = $word->height;
        }

        if ($current !== null) {
            $boxes[] = $this->box($current);
        }

        return $boxes;
    }

    /** @param  array{page: int, left: float, right: float, top: float, bottom: float}  $line */
    private function box(array $line): LineBox
    {
        return new LineBox($line['page'], $line['left'], $line['top'], $line['right'] - $line['left'], $line['bottom'] - $line['top']);
    }

    /** @param  list<int>  $offsets */
    private function firstIndexAtOrAfter(array $offsets, int $start): int
    {
        $low = 0;
        $high = count($offsets);
        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            if ($offsets[$mid] < $start) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }

        return $low;
    }
}
