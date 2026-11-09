<?php

namespace App\Services\Reports\Mapping;

/** Builds the printed Submission ID, for example `trn:oid:::3117:646033185`, from settings plus the number (spec 0012, AC-1, AC-4). */
class ReportIdFormatter
{
    public function __construct(
        private string $prefix,
        private string $account,
    ) {}

    public function format(int $number): string
    {
        return "{$this->prefix}{$this->account}:{$number}";
    }
}
