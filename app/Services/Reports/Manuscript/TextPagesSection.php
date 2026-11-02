<?php

namespace App\Services\Reports\Manuscript;

use App\Services\Reports\Parts\Highlight;
use App\Services\Reports\Parts\MarginBadge;
use App\Services\Reports\ReportSection;
use App\Services\Reports\ReportTheme;
use App\Services\Reports\SectionCanvas;

/** The manuscript as plain text pages, the safe fallback when the original pages cannot be matched (AC-5). */
final class TextPagesSection implements ReportSection
{
    /**
     * @param  array<int, list<Highlight>>  $highlights  keyed by page number, starting at 1
     * @param  array<int, list<MarginBadge>>  $badges  keyed by page number, starting at 1
     * @param  array<int, list<LineBox>>  $outlines  thin outlines for checking word positions, keyed by page number
     */
    public function __construct(
        private readonly TextLayout $layout,
        private readonly string $label = 'Integrity Submission',
        private readonly array $highlights = [],
        private readonly array $badges = [],
        private readonly array $outlines = [],
    ) {}

    public function label(): string
    {
        return $this->label;
    }

    public function pageCount(): int
    {
        return $this->layout->pageCount();
    }

    public function write(SectionCanvas $canvas): void
    {
        foreach ($this->layout->pages as $index => $runs) {
            $page = $index + 1;
            $canvas->newPage(TextPageLayout::PAGE_W, TextPageLayout::PAGE_H);
            foreach ($runs as $run) {
                $canvas->text($run->x, $run->top, $run->text, TextPageLayout::SIZE, false, ReportTheme::TEXT);
            }
            $canvas->overlay($this->highlights[$page] ?? [], $this->badges[$page] ?? []);
            foreach ($this->outlines[$page] ?? [] as $outline) {
                $canvas->outline($outline->x, $outline->y, $outline->width, $outline->height);
            }
        }
    }
}
