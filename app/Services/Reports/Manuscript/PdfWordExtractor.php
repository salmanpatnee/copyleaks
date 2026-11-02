<?php

namespace App\Services\Reports\Manuscript;

interface PdfWordExtractor
{
    /**
     * One entry per page, in page order.
     *
     * @return list<PdfPageWords>
     */
    public function extract(string $absolutePath): array;
}
