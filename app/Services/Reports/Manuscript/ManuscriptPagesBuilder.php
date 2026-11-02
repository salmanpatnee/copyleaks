<?php

namespace App\Services\Reports\Manuscript;

use App\Enums\ManuscriptFallbackReason;
use App\Enums\ManuscriptMode;
use App\Exceptions\ReportRenderFailed;
use App\Models\Scan;
use App\Services\Reports\ImportedPdfSection;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Builds the manuscript pages of a completed scan from its stored PDF and its stored `text` artifact.
 * Never calls Copyleaks, never writes to the database and never logs manuscript text.
 *
 * Check order (the first that fails decides): Word file, missing text and no text stop the build;
 * then import_failed, rotated_page and low_match fall back to text pages.
 */
class ManuscriptPagesBuilder
{
    public function __construct(
        private readonly CrawledTextReader $reader,
        private readonly PdfWordExtractor $extractor,
        private readonly TextAligner $aligner,
        private readonly TextPageLayout $textLayout,
    ) {}

    /** @throws ReportRenderFailed */
    public function build(Scan $scan): ManuscriptPages
    {
        $submission = $scan->submission;
        if ($submission->isWord()) {
            throw ReportRenderFailed::wordNotSupported();
        }

        $text = $this->reader->read($scan) ?? throw ReportRenderFailed::crawledTextMissing();
        if (! $text->hasLettersOrDigits()) {
            throw ReportRenderFailed::noText();
        }

        $path = Storage::disk('local')->path($submission->file_path);

        try {
            $importedPages = ImportedPdfSection::forFile($path)->pageCount();
        } catch (ReportRenderFailed) {
            return $this->textPages($text, ManuscriptFallbackReason::ImportFailed);
        }

        try {
            $pages = $this->extractor->extract($path);
        } catch (Throwable) {
            return $this->textPages($text, ManuscriptFallbackReason::LowMatch);
        }

        if (count($pages) !== $importedPages) {
            return $this->textPages($text, ManuscriptFallbackReason::ImportFailed);
        }

        foreach ($pages as $page) {
            if ($page->box->rotation !== 0) {
                return $this->textPages($text, ManuscriptFallbackReason::RotatedPage);
            }
        }

        $alignment = $this->aligner->align($text, $pages);
        if ($alignment->overallShare < (float) config('report.manuscript.min_match_share')
            || $alignment->minPageShare() < (float) config('report.manuscript.min_page_share')) {
            return $this->textPages($text, ManuscriptFallbackReason::LowMatch, $alignment);
        }

        return new ManuscriptPages(
            ManuscriptMode::Original,
            null,
            $pages,
            $alignment,
            static fn (string $label, array $highlights, array $badges, array $outlines) => ImportedPdfSection::forFile($path, $label, $highlights, $badges, $outlines),
            $alignment,
        );
    }

    private function textPages(CrawledText $text, ManuscriptFallbackReason $reason, ?Alignment $measured = null): ManuscriptPages
    {
        $layout = $this->textLayout->layout($text);

        return new ManuscriptPages(
            ManuscriptMode::Text,
            $reason,
            $layout->words,
            $layout->alignment,
            static fn (string $label, array $highlights, array $badges, array $outlines) => new TextPagesSection($layout, $label, $highlights, $badges, $outlines),
            $measured,
        );
    }
}
