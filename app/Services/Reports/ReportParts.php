<?php

namespace App\Services\Reports;

use App\Services\Reports\Parts\Callout;
use App\Services\Reports\Parts\DocumentDetails;
use App\Services\Reports\Parts\MatchGroupRow;
use App\Services\Reports\Parts\SourceRow;

/**
 * The shared drawing parts. This class works out the numbers (positions, fitted names, percent
 * labels); the Blade partials in resources/views/reports/parts hold the structure.
 */
final class ReportParts
{
    private readonly SourceNameFitter $fitter;

    public function __construct(
        private readonly SectionCanvas $canvas,
        private readonly ReportAssets $assets,
    ) {
        $this->fitter = new SourceNameFitter($canvas);
    }

    public function divider(float $y, float $x = ReportTheme::MARGIN_X, float $width = 540.0): void
    {
        $this->canvas->view('reports.parts.divider', ['x' => $x, 'y' => $y, 'w' => $width]);
    }

    public function text(float $x, float $y, ?string $text, string $class = 'body', ?float $width = null, ?string $color = null, string $align = 'left'): void
    {
        if ($text === null || trim($text) === '') {
            return;
        }

        // A set width stops mPDF from wrapping short text that it sizes too tightly.
        $width ??= max(20.0, ReportTheme::PAGE_W - ReportTheme::MARGIN_X - $x);
        $this->canvas->view('reports.parts.text', ['x' => $x, 'y' => $y, 'w' => $width, 'class' => $class, 'color' => $color, 'text' => $text, 'align' => $align]);
    }

    public function icon(string $file, float $x, float $y, float $w, float $h): void
    {
        $this->canvas->image($this->assets->iconPath($file), $x, $y, $w, $h);
    }

    public function callout(Callout $callout): void
    {
        $this->canvas->view('reports.parts.callout', ['callout' => $callout]);
    }

    /** One match group line. `$y` is the top of the first text line. */
    public function matchGroupRow(MatchGroupRow $row, float $y, float $x = ReportTheme::MARGIN_X, float $textX = 53.0): void
    {
        $style = $row->style;
        $box = $style->iconBox();
        if ($box['circle']) {
            $this->canvas->view('reports.parts.circle', ['x' => $x, 'y' => $y - 0.5, 'w' => 12.5, 'h' => 12.0, 'r' => 6.0, 'bg' => $style->color()]);
        }
        $this->icon($style->icon(), $x + $box['dx'], $y + $box['dy'], $box['w'], $box['h']);

        $count = (string) $row->count;
        $this->text($textX, $y, $count, 'body semi');
        $nameX = $textX + $this->canvas->textWidth($count, ReportTheme::SIZE_BODY, true) + 3;
        $this->text($nameX, $y, $style->label()."\u{00A0}\u{00A0}".PercentFormat::label($row->percent));
        $this->text($textX, $y + 10, $style->description(), 'body muted');
    }

    /** One Top Sources row, 48 pt high. `$y` is the top of the pills. */
    public function sourceRow(SourceRow $row, float $y): void
    {
        $color = SourceColor::forRank($row->rank);
        $this->canvas->view('reports.parts.pill', ['x' => 36, 'y' => $y, 'w' => 24.5, 'h' => 15, 'bg' => $color->strong, 'class' => 'pill', 'text' => (string) $row->rank]);
        if ($row->category !== null) {
            $this->canvas->view('reports.parts.pill', ['x' => 66, 'y' => $y, 'w' => 70, 'h' => 15, 'bg' => $color->tint, 'class' => 'pill-tint', 'text' => $row->category]);
        }
        $this->text(ReportTheme::MARGIN_X, $y + 19.4, $this->fitter->fit($row->name), 'source');
        $this->text(ReportTheme::PCT_X, $y + 19.4, PercentFormat::label($row->percent), 'source');
        $this->divider($y + 38, ReportTheme::MARGIN_X, ReportTheme::ROW_DIVIDER_END_X - ReportTheme::MARGIN_X);
    }

    /** The grey Pages / Words / Characters box on the cover. Lines with no value are left out. */
    public function statsBox(DocumentDetails $d): void
    {
        $lines = array_values(array_filter([
            $d->pages === null ? null : number_format($d->pages).' '.($d->pages === 1 ? 'Page' : 'Pages'),
            $d->words === null ? null : number_format($d->words).' '.($d->words === 1 ? 'Word' : 'Words'),
            $d->characters === null ? null : number_format($d->characters).' '.($d->characters === 1 ? 'Character' : 'Characters'),
        ]));

        if ($lines === []) {
            return;
        }

        $this->canvas->view('reports.parts.circle', ['x' => 403, 'y' => 470, 'w' => 89, 'h' => 78, 'r' => 0, 'bg' => ReportTheme::STATS_BG]);
        foreach ($lines as $i => $line) {
            $this->text(415, 484.5 + 22 * $i, $line, 'body semi');
        }
    }
}
