<?php

namespace App\Services\Reports;

use App\Enums\ReportKind;

final readonly class ReportDocument
{
    /**
     * @param  list<ReportSection>  $sections
     */
    public function __construct(
        public ReportKind $kind,
        public string $headerId,
        public array $sections,
    ) {}

    public function totalPages(): int
    {
        return array_sum(array_map(static fn (ReportSection $s): int => $s->pageCount(), $this->sections));
    }
}
