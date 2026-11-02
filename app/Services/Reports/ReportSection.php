<?php

namespace App\Services\Reports;

/** One part of a report (cover, overview, manuscript pages). New reports add sections and never change the engine. */
interface ReportSection
{
    /** The name shown in the header and footer, for example `Cover Page`. */
    public function label(): string;

    /** How many pages write() will make. Known before drawing so every page can show the true total. */
    public function pageCount(): int;

    public function write(SectionCanvas $canvas): void;
}
