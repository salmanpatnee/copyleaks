<?php

namespace App\Services\Reports;

use App\Exceptions\ReportRenderFailed;
use App\Services\Reports\Parts\Highlight;
use App\Services\Reports\Parts\MarginBadge;
use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use Throwable;

/**
 * What a section talks to. Sections think in points; only this class turns them into the
 * millimetres mPDF uses, and sections never touch mPDF directly. It also draws the shared
 * header and footer strip on every page, last, so it sits over everything else.
 */
final class SectionCanvas implements TextMeasurer
{
    private int $written = 0;

    private bool $pageOpen = false;

    private float $pageW = ReportTheme::PAGE_W;

    private float $pageH = ReportTheme::PAGE_H;

    public function __construct(
        private readonly Mpdf $mpdf,
        private readonly ReportAssets $assets,
        private readonly string $label,
        private readonly string $headerId,
        private readonly int $totalPages,
        private readonly int $firstPageNumber,
        private readonly int $promisedPages,
    ) {}

    public function pagesWritten(): int
    {
        return $this->written;
    }

    /** Starts a new page (US Letter by default). The previous page gets its strip first. */
    public function newPage(float $width = ReportTheme::PAGE_W, float $height = ReportTheme::PAGE_H): void
    {
        $this->finishPage();

        if ($this->written >= $this->promisedPages) {
            throw ReportRenderFailed::overflow();
        }

        $this->pageW = $width;
        $this->pageH = $height;
        $this->mpdf->AddPageByArray([
            'orientation' => 'P',
            'newformat' => [Pt::toMm($width), Pt::toMm($height)],
            'mgl' => 0, 'mgr' => 0, 'mgt' => 0, 'mgb' => 0, 'mgh' => 0, 'mgf' => 0,
        ]);
        $this->written++;
        $this->pageOpen = true;
    }

    /**
     * Writes a Blade view (absolutely placed parts) onto the current page.
     *
     * @param  view-string  $view
     * @param  array<string, mixed>  $data
     */
    public function view(string $view, array $data = []): void
    {
        $this->mpdf->WriteHTML(view($view, $data)->render(), HTMLParserMode::HTML_BODY);
    }

    public function image(string $path, float $x, float $y, float $w, float $h): void
    {
        $this->mpdf->Image($path, Pt::toMm($x), Pt::toMm($y), Pt::toMm($w), Pt::toMm($h));
    }

    /** Draws plain text. `$top` is the top of the text box, as the design spec measures it. */
    public function text(float $x, float $top, string $text, float $size, bool $bold, string $color, string $family = ReportFonts::NOTO): void
    {
        $ascent = $family === ReportFonts::LEXEND ? ReportTheme::ASCENT_LEXEND : ReportTheme::ASCENT_NOTO;
        $this->mpdf->SetFont($family, $bold ? 'B' : '', $size);
        $this->mpdf->SetTextColor(...ReportTheme::rgb($color));
        $this->mpdf->Text(Pt::toMm($x), Pt::toMm($top + $ascent * $size), $text);
    }

    public function textWidth(string $text, float $size, bool $bold, string $family = ReportFonts::NOTO): float
    {
        $this->mpdf->SetFont($family, $bold ? 'B' : '', $size);

        return $this->mpdf->GetStringWidth($text) * 72 / 25.4;
    }

    public function roundedRect(float $x, float $y, float $w, float $h, float $radius, string $color): void
    {
        $this->mpdf->SetFillColor(...ReportTheme::rgb($color));
        $this->mpdf->RoundedRect(Pt::toMm($x), Pt::toMm($y), Pt::toMm($w), Pt::toMm($h), Pt::toMm($radius), 'F');
    }

    /**
     * Imports page `$pageNumber` of a stored PDF as a new page that keeps its own size.
     *
     * @return int the page count of the file
     *
     * @throws ReportRenderFailed
     */
    public function importPage(string $absolutePath, int $pageNumber): int
    {
        try {
            $count = $this->mpdf->setSourceFile($absolutePath);
            $template = $this->mpdf->importPage($pageNumber);
            $size = $this->mpdf->getTemplateSize($template);
        } catch (Throwable $e) {
            throw PdfImportErrors::map($e);
        }

        if (! is_array($size)) {
            throw ReportRenderFailed::unreadablePdf();
        }

        [$widthMm, $heightMm] = [(float) $size['width'], (float) $size['height']];
        $this->newPage($widthMm * 72 / 25.4, $heightMm * 72 / 25.4);
        $this->mpdf->useTemplate($template, 0, 0, $widthMm, $heightMm);

        return $count;
    }

    /**
     * Draws overlays on the current page in the fixed order: highlights (multiply blend at 80%,
     * then back to normal), then badges. The strip comes last, when the page is finished.
     *
     * @param  list<Highlight>  $highlights
     * @param  list<MarginBadge>  $badges
     */
    public function overlay(array $highlights, array $badges): void
    {
        foreach ($highlights as $highlight) {
            $this->mpdf->SetAlpha(ReportTheme::HIGHLIGHT_OPACITY, 'Multiply');
            $this->mpdf->SetFillColor(...ReportTheme::rgb($highlight->style->color()));
            $this->mpdf->Rect(Pt::toMm($highlight->x), Pt::toMm($highlight->y), Pt::toMm($highlight->width), Pt::toMm($highlight->height), 'F');
            $this->mpdf->SetAlpha(1, 'Normal');
        }

        foreach ($badges as $badge) {
            $this->drawBadge($badge);
        }
    }

    /** Draws a thin red outline. Only for the manuscript check PDF (`report:manuscript`). */
    public function outline(float $x, float $y, float $w, float $h): void
    {
        $this->mpdf->SetLineWidth(Pt::toMm(0.4));
        $this->mpdf->SetDrawColor(220, 20, 60);
        $this->mpdf->Rect(Pt::toMm($x), Pt::toMm($y), Pt::toMm($w), Pt::toMm($h), 'D');
    }

    /** Draws the strip on the last page. Called once at the end of the section. */
    public function finish(): void
    {
        $this->finishPage();
    }

    private function drawBadge(MarginBadge $badge): void
    {
        $circle = 15.5;
        $this->roundedRect($badge->x, $badge->y, $circle, $circle, $circle / 2, $badge->style->color());
        // The badge icon is the group icon enlarged to about 6 x 6.6 pt, centered in the circle.
        $box = $badge->style->iconBox();
        $w = $box['w'] * 1.1;
        $h = $box['h'] * 1.1;
        $this->image($this->assets->iconPath($badge->style->icon()), $badge->x + ($circle - $w) / 2, $badge->y + ($circle - $h) / 2, $w, $h);

        $pillX = $badge->x + $circle + 1.0;
        $this->roundedRect($pillX, $badge->y, 17.7, $circle, 7.75, SourceColor::forRank($badge->rank)->strong);
        $label = (string) $badge->rank;
        $width = $this->textWidth($label, ReportTheme::SIZE_BADGE, true);
        $this->text($pillX + (17.7 - $width) / 2, $badge->y + ($circle - ReportTheme::SIZE_BADGE * 1.362) / 2, $label, ReportTheme::SIZE_BADGE, true, ReportTheme::ON_DARK);
    }

    private function finishPage(): void
    {
        if (! $this->pageOpen) {
            return;
        }

        $this->pageOpen = false;
        $number = $this->firstPageNumber + $this->written - 1;

        $this->drawStrip($number, ReportTheme::HEADER_LOGO_Y, ReportTheme::HEADER_TEXT_TOP);
        $this->drawStrip(
            $number,
            $this->pageH - ReportTheme::FOOTER_LOGO_FROM_BOTTOM,
            $this->pageH - ReportTheme::FOOTER_TEXT_FROM_BOTTOM,
        );
    }

    private function drawStrip(int $number, float $logoY, float $textTop): void
    {
        $this->image($this->assets->logoPath(), ReportTheme::MARGIN_X, $logoY, ReportTheme::LOGO_W, ReportTheme::LOGO_H);

        $size = ReportTheme::SIZE_SMALL;
        $page = "Page {$number} of {$this->totalPages} - {$this->label}";
        $this->text(ReportTheme::STRIP_LABEL_X, $textTop, $page, $size, false, ReportTheme::TEXT);

        $right = $this->pageW - ReportTheme::MARGIN_X;
        $idX = $right - $this->textWidth($this->headerId, $size, false);
        $this->text($idX, $textTop, $this->headerId, $size, false, ReportTheme::TEXT);

        $label = 'Submission ID';
        $labelX = $idX - ReportTheme::STRIP_GAP - $this->textWidth($label, $size, false);
        $this->text($labelX, $textTop, $label, $size, false, ReportTheme::TEXT);
    }
}
