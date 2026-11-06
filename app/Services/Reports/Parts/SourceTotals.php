<?php

namespace App\Services\Reports\Parts;

/** The three Top Sources totals of the overview summary block. None is nullable: no totals at all means a null object on the section. */
final readonly class SourceTotals
{
    public function __construct(
        public float $internet,
        public float $publications,
        public float $studentPapers,
    ) {}
}
