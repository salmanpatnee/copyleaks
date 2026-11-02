<?php

namespace App\Services\Reports\Sample;

use App\Services\Reports\ReportDocument;

/** A sample document plus the temp files made for it (deleted after the build). */
final readonly class SampleReport
{
    /**
     * @param  list<string>  $tempFiles
     */
    public function __construct(
        public ReportDocument $document,
        public array $tempFiles,
    ) {}
}
