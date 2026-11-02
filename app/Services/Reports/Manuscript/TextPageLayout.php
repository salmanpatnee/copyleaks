<?php

namespace App\Services\Reports\Manuscript;

use App\Services\Reports\TextMeasurer;

/**
 * Lays the whole Copyleaks text out as one continuous flow on Letter pages (AC-5): 48 lines a page,
 * each newline starts a line, long lines wrap at word gaps, a word wider than the line is split by
 * characters. Gives a word box for every word, so every letter and digit is found.
 */
class TextPageLayout
{
    public const PAGE_W = 612.0;

    public const PAGE_H = 792.0;

    public const LEFT = 36.0;

    public const RIGHT = 576.0;

    public const TOP = 60.0;

    public const SIZE = 10.0;

    public const LINE = 14.0;

    public const LINES_PER_PAGE = 48;

    /** Height of a word box, centered in the 14 pt line. */
    private const BOX_HEIGHT = 12.0;

    public function __construct(
        private readonly TextMeasurer $measurer,
        private readonly TextNormalizer $normalizer,
    ) {}

    public function layout(CrawledText $text): TextLayout
    {
        $drawn = [];
        $pieces = [];
        foreach ($this->lines($text) as $lineNo => $line) {
            $page = intdiv($lineNo, self::LINES_PER_PAGE);
            $top = self::TOP + ($lineNo % self::LINES_PER_PAGE) * self::LINE;
            foreach ($line as $piece) {
                $drawn[$page][] = new TextRun($piece['x'], $top, $piece['text']);
                $pieces[$page][] = [$piece, $top];
            }
        }

        $pageCount = $drawn === [] ? 1 : array_key_last($drawn) + 1;
        $cleaned = $this->normalizer->cleanAll($text);
        $total = $cleaned->length();
        $cursor = 0;
        $found = [];
        $words = [];
        $pages = [];

        for ($page = 0; $page < $pageCount; $page++) {
            $pages[] = $drawn[$page] ?? [];
            $boxes = [];
            foreach ($pieces[$page] ?? [] as $wordIndex => [$piece, $top]) {
                $letters = '';
                while ($cursor < $total && $cleaned->offsets[$cursor] < $piece['end']) {
                    if ($cleaned->offsets[$cursor] >= $piece['start']) {
                        $letters .= $cleaned->chars[$cursor];
                        $found[$cleaned->offsets[$cursor]] = [$page, $wordIndex];
                    }
                    $cursor++;
                }
                $boxes[] = new WordBox($piece['x'], $top + (self::LINE - self::BOX_HEIGHT) / 2, $piece['width'], self::BOX_HEIGHT, $letters);
            }
            $words[] = new PdfPageWords(new PdfPageBox(0.0, 0.0, self::PAGE_W, self::PAGE_H, 0), $boxes);
        }

        return new TextLayout($pages, $words, new Alignment($found, $cleaned->offsets, 1.0, array_fill(1, $pageCount, 1.0)));
    }

    /**
     * Every line of the flow, each a list of pieces. A piece is a word, or a part of a word that was
     * too wide for one line. `start` and `end` are Copyleaks offsets (UTF-16 units, end exclusive).
     *
     * @return list<list<array{x: float, width: float, text: string, start: int, end: int}>>
     */
    private function lines(CrawledText $text): array
    {
        $space = $this->measurer->textWidth(' ', self::SIZE, false);

        $lines = [];
        $line = [];
        $x = self::LEFT;
        $word = '';
        $wordStart = 0;
        $offset = 0;

        $flush = function () use (&$lines, &$line, &$x): void {
            $lines[] = $line;
            $line = [];
            $x = self::LEFT;
        };

        $place = function (string $word, int $start, int $end) use (&$line, &$x, $space, $flush): void {
            $width = $this->measurer->textWidth($word, self::SIZE, false);

            if ($width > self::RIGHT - self::LEFT) {
                $this->placeSplit($word, $start, $line, $x, $flush, $line === [] ? 0.0 : $space);

                return;
            }

            $gap = $line === [] ? 0.0 : $space;
            if ($line !== [] && $x + $gap + $width > self::RIGHT) {
                $flush();
                $gap = 0.0;
            }

            $line[] = ['x' => $x + $gap, 'width' => $width, 'text' => $word, 'start' => $start, 'end' => $end];
            $x += $gap + $width;
        };

        foreach (mb_str_split($text->value) as $character) {
            $isBreak = $character === "\n";

            if ($isBreak || $character === ' ' || $character === "\t" || $character === "\r") {
                if ($word !== '') {
                    $place($word, $wordStart, $offset);
                    $word = '';
                }
                if ($isBreak) {
                    $flush();
                }
            } else {
                if ($word === '') {
                    $wordStart = $offset;
                }
                $word .= $character;
            }

            $offset += mb_ord($character) > 0xFFFF ? 2 : 1;
        }

        if ($word !== '') {
            $place($word, $wordStart, $offset);
        }
        $flush();

        return $this->withoutTrailingEmpty($lines);
    }

    /**
     * @param  list<list<array{x: float, width: float, text: string, start: int, end: int}>>  $lines
     * @return list<list<array{x: float, width: float, text: string, start: int, end: int}>>
     */
    private function withoutTrailingEmpty(array $lines): array
    {
        while ($lines !== [] && count($lines[array_key_last($lines)]) === 0) {
            array_pop($lines);
        }

        return $lines;
    }

    /**
     * A word wider than a whole line: filled character by character, wrapping where the line ends.
     *
     * @param  list<array{x: float, width: float, text: string, start: int, end: int}>  $line
     */
    private function placeSplit(string $word, int $start, array &$line, float &$x, callable $flush, float $gap): void
    {
        $piece = '';
        $pieceStart = $start;
        $pieceWidth = 0.0;
        $offset = $start;
        $x += $gap;

        foreach (mb_str_split($word) as $character) {
            $width = $this->measurer->textWidth($character, self::SIZE, false);

            if ($piece !== '' && $x + $pieceWidth + $width > self::RIGHT) {
                $line[] = ['x' => $x, 'width' => $pieceWidth, 'text' => $piece, 'start' => $pieceStart, 'end' => $offset];
                $flush();
                $piece = '';
                $pieceWidth = 0.0;
                $pieceStart = $offset;
            }

            $piece .= $character;
            $pieceWidth += $width;
            $offset += mb_ord($character) > 0xFFFF ? 2 : 1;
        }

        if ($piece !== '') {
            $line[] = ['x' => $x, 'width' => $pieceWidth, 'text' => $piece, 'start' => $pieceStart, 'end' => $offset];
            $x += $pieceWidth;
        }
    }
}
