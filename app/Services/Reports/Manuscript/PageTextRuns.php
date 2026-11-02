<?php

namespace App\Services\Reports\Manuscript;

use Smalot\PdfParser\Page;

/**
 * The text runs of a page with their true position on the page, in PDF points from the bottom left.
 *
 * `smalot/pdfparser` stacks `cm` matrices in the wrong order (old x new instead of new x old), which
 * moves text down the page when a file flips and then scales the page, as Google Docs does
 * (`1 0 0 -1 0 842 cm` then `.75 0 0 .75 72 72 cm`). This class keeps the matrices itself and takes
 * only the decoded text and the command list from smalot.
 */
class PageTextRuns
{
    private const IDENTITY = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];

    /** @return list<array{0: array<int, float>, 1: string, 2: string, 3: float}> matrix, text, font key, font size. The font key is empty before the first `Tf` */
    public function of(Page $page): array
    {
        $texts = $page->getTextArray();
        $runs = [];
        $ctm = self::IDENTITY;
        $stack = [];
        $line = self::IDENTITY;
        $leading = 0.0;
        $font = ['', 0.0];
        $hasFont = false;

        foreach ($page->getDataCommands() as $command) {
            if (count($runs) >= count($texts)) {
                break;
            }
            $operands = is_string($command['c']) ? $this->numbers($command['c']) : [];

            switch ($command['o']) {
                case 'q':
                    $stack[] = $ctm;
                    break;
                case 'Q':
                    $ctm = array_pop($stack) ?? self::IDENTITY;
                    break;
                case 'cm':
                    $ctm = $this->multiply($operands, $ctm);
                    break;
                case 'BT':
                    $line = self::IDENTITY;
                    break;
                case 'Tm':
                    $line = $operands;
                    break;
                case 'TL':
                    $leading = $operands[0] ?? 0.0;
                    break;
                case 'Td':
                    $line = $this->multiply([1.0, 0.0, 0.0, 1.0, $operands[0] ?? 0.0, $operands[1] ?? 0.0], $line);
                    break;
                case 'TD':
                    $leading = -($operands[1] ?? 0.0);
                    $line = $this->multiply([1.0, 0.0, 0.0, 1.0, $operands[0] ?? 0.0, $operands[1] ?? 0.0], $line);
                    break;
                case 'T*':
                    $line = $this->multiply([1.0, 0.0, 0.0, 1.0, 0.0, -$leading], $line);
                    break;
                case 'Tf':
                    [$key, $size] = array_pad(explode(' ', is_string($command['c']) ? $command['c'] : '', 2), 2, '0');
                    $font = [$key, (float) $size];
                    $hasFont = true;
                    break;
                case 'Tj':
                case 'TJ':
                    $runs[] = $this->run($line, $ctm, $texts[count($runs)], $font, $hasFont);
                    break;
                case "'":
                case '"':
                    $line = $this->multiply([1.0, 0.0, 0.0, 1.0, 0.0, -$leading], $line);
                    $runs[] = $this->run($line, $ctm, $texts[count($runs)], $font, $hasFont);
                    break;
            }
        }

        return $runs;
    }

    /**
     * @param  array<int, float>  $line
     * @param  array<int, float>  $ctm
     * @param  array{0: string, 1: float}  $font
     * @return array{0: array<int, float>, 1: string, 2: string, 3: float}
     */
    private function run(array $line, array $ctm, mixed $text, array $font, bool $hasFont): array
    {
        return [$this->multiply($line, $ctm), (string) $text, $hasFont ? $font[0] : '', $hasFont ? $font[1] : 0.0];
    }

    /**
     * `$a` first, then `$b`: the order PDF uses for both text matrices and `cm`.
     *
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     * @return array<int, float>
     */
    private function multiply(array $a, array $b): array
    {
        if (count($a) < 6) {
            return $b;
        }

        return [
            $a[0] * $b[0] + $a[1] * $b[2],
            $a[0] * $b[1] + $a[1] * $b[3],
            $a[2] * $b[0] + $a[3] * $b[2],
            $a[2] * $b[1] + $a[3] * $b[3],
            $a[4] * $b[0] + $a[5] * $b[2] + $b[4],
            $a[4] * $b[1] + $a[5] * $b[3] + $b[5],
        ];
    }

    /** @return array<int, float> */
    private function numbers(string $operands): array
    {
        return array_map(floatval(...), preg_split('/\s+/', trim($operands), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
