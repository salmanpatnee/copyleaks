<?php

namespace App\Services\Reports\Sample;

use App\Services\Reports\Parts\DocumentDetails;
use App\Services\Reports\ReportAssets;
use App\Services\Reports\ReportParts;
use App\Services\Reports\ReportSection;
use App\Services\Reports\ReportTheme;
use App\Services\Reports\SectionCanvas;

/** Throwaway cover page for the sample (the real cover is feature 11). Null fields are left out. */
final class SampleCoverSection implements ReportSection
{
    public function __construct(
        private readonly DocumentDetails $details,
        private readonly ReportAssets $assets,
    ) {}

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

        $parts->text(36, 336, $d->author, 'author');
        $parts->text(36, 369, $d->title, 'title');
        if ($d->assignment !== null && trim($d->assignment) !== '') {
            $parts->icon('clipboard.png', 36, 397, 12, 12);
            $parts->text(56, 397.4, $d->assignment, 'body muted', null, null);
        }

        $parts->divider(425);
        $parts->text(36, 443.3, 'Document Details', 'heading');

        $fields = array_filter([
            'Submission ID' => $d->submissionId,
            'Submission Date' => $d->submissionDate,
            'Download Date' => $d->downloadDate,
            'File Name' => $d->fileName,
            'File Size' => $d->fileSize,
        ], static fn (?string $v): bool => $v !== null && trim($v) !== '');

        $i = 0;
        foreach ($fields as $label => $value) {
            $top = 472.5 + 36 * $i++;
            $parts->text(36, $top, $label, 'body semi muted');
            $parts->text(36, $top + 14, $value, 'body semi', 340.0, ReportTheme::TEXT);
        }

        $parts->statsBox($d);
    }
}
