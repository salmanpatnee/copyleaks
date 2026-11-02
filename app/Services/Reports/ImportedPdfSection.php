<?php

namespace App\Services\Reports;

use App\Exceptions\ReportRenderFailed;
use App\Services\Reports\Parts\Highlight;
use App\Services\Reports\Parts\MarginBadge;
use Mpdf\Mpdf;
use Throwable;

/** Imports every page of a stored PDF (each keeps its own size) and draws highlights and badges on them. */
final class ImportedPdfSection implements ReportSection
{
    /**
     * @param  array<int, list<Highlight>>  $highlights  keyed by page number, starting at 1
     * @param  array<int, list<MarginBadge>>  $badges  keyed by page number, starting at 1
     */
    public function __construct(
        private readonly string $absolutePath,
        private readonly int $pages,
        private readonly string $label = 'Integrity Submission',
        private readonly array $highlights = [],
        private readonly array $badges = [],
    ) {}

    /**
     * Counts the pages first, so a file that cannot be imported fails before any drawing.
     *
     * @param  array<int, list<Highlight>>  $highlights
     * @param  array<int, list<MarginBadge>>  $badges
     *
     * @throws ReportRenderFailed
     */
    public static function forFile(string $absolutePath, string $label = 'Integrity Submission', array $highlights = [], array $badges = []): self
    {
        try {
            $pages = (new Mpdf(['tempDir' => (string) config('report.temp_dir')]))->setSourceFile($absolutePath);
        } catch (Throwable $e) {
            throw PdfImportErrors::map($e);
        }

        return new self($absolutePath, $pages, $label, $highlights, $badges);
    }

    public function label(): string
    {
        return $this->label;
    }

    public function pageCount(): int
    {
        return $this->pages;
    }

    public function write(SectionCanvas $canvas): void
    {
        for ($page = 1; $page <= $this->pages; $page++) {
            $canvas->importPage($this->absolutePath, $page);
            $canvas->overlay($this->highlights[$page] ?? [], $this->badges[$page] ?? []);
        }
    }
}
