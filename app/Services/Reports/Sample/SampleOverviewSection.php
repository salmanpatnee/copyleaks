<?php

namespace App\Services\Reports\Sample;

use App\Services\Reports\Parts\Callout;
use App\Services\Reports\Parts\MatchGroupRow;
use App\Services\Reports\Parts\SourceRow;
use App\Services\Reports\PercentFormat;
use App\Services\Reports\ReportAssets;
use App\Services\Reports\ReportFonts;
use App\Services\Reports\ReportParts;
use App\Services\Reports\ReportSection;
use App\Services\Reports\ReportTheme;
use App\Services\Reports\SectionCanvas;
use App\Services\Reports\SourceRowPager;

/** Throwaway overview pages for the sample (the real overview is feature 12). */
final class SampleOverviewSection implements ReportSection
{
    private const FIRST_LIST_TOP = 245.5;

    private const LATER_LIST_TOP = 75.0;

    /** @var list<int> */
    private array $perPage;

    /**
     * @param  list<MatchGroupRow>  $groups
     * @param  list<SourceRow>  $sources
     * @param  list<string>  $filters
     */
    public function __construct(
        private readonly float $score,
        private readonly array $groups,
        private readonly array $sources,
        private readonly array $filters,
        private readonly ReportAssets $assets,
    ) {
        $this->perPage = SourceRowPager::split(count($sources), self::FIRST_LIST_TOP, self::LATER_LIST_TOP);
    }

    public function label(): string
    {
        return 'Integrity Overview';
    }

    public function pageCount(): int
    {
        return 1 + count($this->perPage);
    }

    public function write(SectionCanvas $canvas): void
    {
        $parts = new ReportParts($canvas, $this->assets);
        $this->writeFirstPage($canvas, $parts);

        $index = 0;
        foreach ($this->perPage as $n => $take) {
            $canvas->newPage();
            if ($n === 0) {
                $this->writeSummary($parts, 53.3);
                $parts->divider(187.5);
                $parts->text(36, 205.8, 'Top Sources', 'heading');
                $parts->text(36, 224, 'The sources with the highest number of matches within the submission. Overlapping sources will not be displayed.', 'body muted');
                $top = self::FIRST_LIST_TOP;
            } else {
                $top = self::LATER_LIST_TOP;
            }

            foreach (array_slice($this->sources, $index, $take) as $i => $row) {
                $parts->sourceRow($row, $top + ReportTheme::ROW_H * $i);
            }
            $index += $take;
        }
    }

    private function writeFirstPage(SectionCanvas $canvas, ReportParts $parts): void
    {
        $canvas->newPage();
        $scoreLabel = PercentFormat::label($this->score);
        $parts->text(36, 55, $scoreLabel, 'title');
        $scoreWidth = $canvas->textWidth($scoreLabel, ReportTheme::SIZE_TITLE, false, ReportFonts::LEXEND);
        $parts->text(36 + $scoreWidth + 10, 55, 'Overall Similarity', 'title');
        $parts->text(36, 80, 'The combined total of all matches, including overlapping sources, for each database.', 'body muted');

        $divider = 148.5;
        if ($this->filters !== []) {
            $parts->text(36, 100.8, 'Filtered from the Report', 'heading');
            foreach ($this->filters as $i => $item) {
                $y = 122 + 14 * $i;
                $parts->icon('triangle.png', 39, $y + 4, 2, 3);
                $parts->text(48, $y, $item, 'body', null, ReportTheme::TEXT);
            }
            $divider = 122 + 14 * (count($this->filters) - 1) + 26.5;
        }

        $parts->divider($divider);
        $headingTop = $divider + 13.3;
        $this->writeSummary($parts, $headingTop);

        // 291 is the design spec's divider with one filter item; the rest follows the summary block.
        $second = $headingTop + 129.2;
        $parts->divider($second);
        $parts->text(36, $second + 10.8, 'Integrity Flags', 'heading');
        $parts->text(36, $second + 28, '0 Integrity Flags for Review', 'body semi');
        $parts->callout(new Callout(null, [
            "Our system's algorithms look deeply at a document for any inconsistencies that would set it apart from a normal submission. If we notice something strange, we flag it for you to review.",
            "A Flag is not necessarily an indicator of a problem. However, we'd recommend you focus your attention there for further review.",
        ], 301, $second + 27.5, 266, 64));
    }

    /** The Match Groups and Top Sources summary block. `$headingTop` is the top of its headings. */
    private function writeSummary(ReportParts $parts, float $headingTop): void
    {
        $parts->text(36, $headingTop, 'Match Groups', 'heading');
        $parts->text(298.5, $headingTop, 'Top Sources', 'heading');

        foreach ($this->groups as $i => $group) {
            $parts->matchGroupRow($group, $headingTop + 23.2 + 25 * $i);
        }

        // icon file, label, then x, y offset, width and height of the icon (measured from the Turnitin samples)
        $rows = [
            ['source-internet.png', 'Internet sources', 322, 1, 8.5, 8.5],
            ['source-publication.png', 'Publications', 322, 2, 9, 6.5],
            ['source-student.png', 'Submitted works (Student Papers)', 323, 2, 6.5, 6.5],
        ];
        foreach ($rows as $i => [$icon, $label, $iconX, $dy, $w, $h]) {
            $y = $headingTop + 23.2 + 14 * $i;
            $parts->text(298.5, $y, PercentFormat::label($this->sourceShare($label)), 'body');
            $parts->icon($icon, $iconX, $y + $dy, $w, $h);
            $parts->text(336, $y, $label, 'body');
        }
    }

    private function sourceShare(string $label): float
    {
        $category = match (true) {
            str_starts_with($label, 'Internet') => 'Internet',
            str_starts_with($label, 'Publication') => 'Publication',
            default => 'Student papers',
        };

        $total = 0.0;
        foreach ($this->sources as $row) {
            if ($row->category === $category) {
                $total += $row->percent;
            }
        }

        return $total;
    }
}
