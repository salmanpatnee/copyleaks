<?php

namespace App\Services\Reports\Manuscript;

/** The result of laying the Copyleaks text out on report pages: what to draw, and where every word sits. */
final readonly class TextLayout
{
    /**
     * @param  list<list<TextRun>>  $pages  the words to draw, page 1 first
     * @param  list<PdfPageWords>  $words  the word boxes of each page, in the same shape the PDF reader gives
     */
    public function __construct(
        public array $pages,
        public array $words,
        public Alignment $alignment,
    ) {}

    public function pageCount(): int
    {
        return count($this->pages);
    }
}
