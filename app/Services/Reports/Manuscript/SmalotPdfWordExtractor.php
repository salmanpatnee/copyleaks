<?php

namespace App\Services\Reports\Manuscript;

use Smalot\PdfParser\Config;
use Smalot\PdfParser\Font;
use Smalot\PdfParser\Page;
use Smalot\PdfParser\Parser;
use Smalot\PdfParser\PDFObject;

/**
 * Reads words and their positions with `smalot/pdfparser` (matrices come from `PageTextRuns`). Each text run (`Tj` / `TJ`) has a start
 * matrix, a font and a size. Runs are split into words on spaces; neighbouring runs on the same
 * baseline (Google Docs draws one letter per run) join into one word. The two box factors are
 * constants tuned in the spec 0007 proof.
 */
class SmalotPdfWordExtractor implements PdfWordExtractor
{
    /** Distance from the baseline up to the top of a word box, in font sizes. */
    public const TOP_FACTOR = 0.72;

    /** Height of a word box, in font sizes. */
    public const HEIGHT_FACTOR = 0.9;

    private const UNKNOWN_WIDTH = 500.0;

    private const JOIN_GAP = 0.2;

    public function __construct(
        private readonly PdfFontWidths $widths,
        private readonly TextNormalizer $normalizer,
        private readonly PageTextRuns $runs,
    ) {}

    public function extract(string $absolutePath): array
    {
        $config = new Config;
        $config->setDataTmFontInfoHasToBeIncluded(true);
        $document = (new Parser([], $config))->parseFile($absolutePath);

        $pages = [];
        foreach ($document->getPages() as $page) {
            $box = PdfPageBox::fromPage($page);
            $pages[] = new PdfPageWords($box, $this->wordsOf($page, $box));
        }

        return $pages;
    }

    /** @return list<WordBox> */
    private function wordsOf(Page $page, PdfPageBox $box): array
    {
        $fonts = $page->getFonts();
        $words = [];
        $open = null;
        // Text shown before any `Tf` reuses the page's first `Tf`.
        $lastFont = $this->firstFont($page);

        foreach ($this->runs->of($page) as $run) {
            $matrix = $run[0];
            $text = (string) $run[1];
            if ($run[2] !== '') {
                $lastFont = [$run[2], $run[3]];
            }
            [$fontKey, $fontSize] = $lastFont;
            $font = $fonts[$fontKey] ?? null;
            $scaleX = abs((float) $matrix[0]) ?: 1.0;
            $size = $fontSize * (abs((float) $matrix[3]) ?: 1.0);
            $x = (float) $matrix[4];
            $baseline = (float) $matrix[5];

            if ($open !== null && (abs($open['baseline'] - $baseline) > self::JOIN_GAP * $size
                || $x - $open['end'] > self::JOIN_GAP * $size
                || $x - $open['end'] < -$size * 0.5)) {
                $this->close($words, $open, $box);
                $open = null;
            }

            foreach (mb_str_split($text) as $character) {
                $width = $font instanceof Font ? $this->widths->width($font, $fontKey, $character) : null;
                $advance = ($width ?? self::UNKNOWN_WIDTH) / 1000 * $fontSize * $scaleX;

                if (trim($character) === '') {
                    $this->close($words, $open, $box);
                    $open = null;
                } else {
                    $open ??= ['text' => '', 'start' => $x, 'end' => $x, 'baseline' => $baseline, 'size' => $size];
                    $open['text'] .= $character;
                    $open['end'] = $x + $advance;
                }
                $x += $advance;
            }
        }

        $this->close($words, $open, $box);

        return $words;
    }

    /** @return array{0: string, 1: float} the font key and size of the first `Tf` on the page */
    private function firstFont(Page $page): array
    {
        $contents = $page->get('Contents');
        $stream = $contents instanceof PDFObject ? $contents->getContent() : '';
        if (preg_match('~/(\S+)\s+([\d.]+)\s+Tf~', $stream, $m) === 1) {
            return [$m[1], (float) $m[2]];
        }

        return ['', 12.0];
    }

    /**
     * @param  list<WordBox>  $words
     * @param  array{text: string, start: float, end: float, baseline: float, size: float}|null  $open
     */
    private function close(array &$words, ?array $open, PdfPageBox $box): void
    {
        if ($open === null) {
            return;
        }

        $chars = implode('', $this->normalizer->clean($open['text']));
        $width = $open['end'] - $open['start'];
        if ($chars === '' || $width <= 0) {
            return;
        }

        $words[] = new WordBox(
            $open['start'] - $box->x1,
            ($box->y2 - $open['baseline']) - self::TOP_FACTOR * $open['size'],
            $width,
            self::HEIGHT_FACTOR * $open['size'],
            $chars,
        );
    }
}
