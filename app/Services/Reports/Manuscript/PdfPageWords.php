<?php

namespace App\Services\Reports\Manuscript;

/** The words of one PDF page in content stream order, with the page box they sit in. */
final readonly class PdfPageWords
{
    /** @param  list<WordBox>  $words */
    public function __construct(
        public PdfPageBox $box,
        public array $words,
    ) {}
}
