<?php

namespace App\Services\Reports\Cover;

use App\Services\Reports\Parts\DocumentDetails;
use App\Services\Reports\ReportAssets;
use App\Services\Reports\ReportFonts;
use App\Services\Reports\ReportParts;
use App\Services\Reports\ReportSection;
use App\Services\Reports\ReportTheme;
use App\Services\Reports\SectionCanvas;

/**
 * The similarity report cover (spec 0009). A spot with no value is left out. Long text is cut or
 * wrapped by CoverTextFitter, and a 2 line title moves everything below it down 20 pt.
 */
final class CoverSection implements ReportSection
{
    private const TITLE_LINE_HEIGHT = 20.0;

    private readonly CoverTextFitter $fitter;

    public function __construct(
        private readonly DocumentDetails $details,
        private readonly ReportAssets $assets,
    ) {
        $this->fitter = new CoverTextFitter;
    }

    public function label(): string
    {
        return 'Cover Page';
    }

    public function pageCount(): int
    {
        return 1;
    }

    public function write(SectionCanvas $canvas): void
    {
        $canvas->newPage();
        $parts = new ReportParts($canvas, $this->assets);
        $d = $this->details;

        $author = $this->fitter->oneLine((string) $d->author, 540.0, fn (string $t): float => $canvas->textWidth($t, ReportTheme::SIZE_AUTHOR, true, ReportFonts::LEXEND));
        $parts->text(36, 336, $author, 'author nowrap', 540.0);

        $titleLines = $this->fitter->title((string) $d->title, 540.0, fn (string $t): float => $canvas->textWidth($t, ReportTheme::SIZE_TITLE, false, ReportFonts::LEXEND));
        foreach ($titleLines as $i => $line) {
            $parts->text(36, 369 + self::TITLE_LINE_HEIGHT * $i, $line, 'title nowrap', 540.0);
        }
        $shift = self::TITLE_LINE_HEIGHT * max(0, count($titleLines) - 1);

        $assignment = $this->fitter->oneLine((string) $d->assignment, 520.0, fn (string $t): float => $canvas->textWidth($t, ReportTheme::SIZE_ASSIGNMENT, false));
        if ($assignment !== '') {
            $parts->icon('clipboard.png', 36, 397 + $shift, 12, 12);
            $parts->text(56, 397.4 + $shift, $assignment, 'assignment nowrap', 520.0);
        }

        $parts->divider(425 + $shift);
        $parts->text(36, 443.3 + $shift, 'Document Details', 'heading');

        $i = 0;
        foreach ($this->fields() as $label => $value) {
            $top = 472.5 + $shift + 36 * $i++;
            $fitted = $this->fitter->oneLine($value, 340.0, fn (string $t): float => $canvas->textWidth($t, ReportTheme::SIZE_BODY, true));
            $parts->text(36, $top, $label, 'body semi muted nowrap');
            $parts->text(36, $top + 14, $fitted, 'body semi nowrap', 340.0, ReportTheme::TEXT);
        }

        $parts->statsBox($d, 470.0 + $shift);
    }

    /** @return array<string, string> the field pairs that have a value, in cover order */
    private function fields(): array
    {
        $d = $this->details;

        return array_filter([
            'Submission ID' => $d->submissionId,
            'Submission Date' => $d->submissionDate,
            'Download Date' => $d->downloadDate,
            'File Name' => $d->fileName,
            'File Size' => $d->fileSize,
        ], static fn (?string $v): bool => $v !== null && trim($v) !== '');
    }
}
