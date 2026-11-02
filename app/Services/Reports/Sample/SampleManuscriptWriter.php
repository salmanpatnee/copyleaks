<?php

namespace App\Services\Reports\Sample;

use App\Services\Reports\Pt;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/** Makes a made up manuscript PDF (alternating A4 and Letter pages, fake text) for the sample. */
final class SampleManuscriptWriter
{
    public const A4 = [595.32, 841.92];

    public const LETTER = [612.0, 792.0];

    public const FIRST_BASELINE = 84.0;

    public const LINE_HEIGHT = 14.4;

    private const WORDS = ['the', 'research', 'method', 'results', 'show', 'a', 'clear', 'pattern', 'in', 'student', 'writing', 'while', 'sources', 'are', 'cited', 'and', 'quoted', 'with', 'care', 'over', 'time'];

    /** @return list<array{0: float, 1: float}> page sizes, one per page */
    public static function sizes(int $pages): array
    {
        return array_map(static fn (int $i): array => $i % 2 === 0 ? self::A4 : self::LETTER, range(0, max(0, $pages - 1)));
    }

    public function write(string $path, int $pages): void
    {
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'tempDir' => (string) config('report.temp_dir'),
            'autoPageBreak' => false,
            'fontDir' => (new ConfigVariables)->getDefaults()['fontDir'],
            'fontdata' => (new FontVariables)->getDefaults()['fontdata'],
        ]);

        foreach (self::sizes($pages) as $n => [$w, $h]) {
            $mpdf->AddPageByArray(['orientation' => 'P', 'newformat' => [Pt::toMm($w), Pt::toMm($h)], 'mgl' => 0, 'mgr' => 0, 'mgt' => 0, 'mgb' => 0]);
            $mpdf->SetFont('dejavuserif', '', 12);
            $mpdf->SetTextColor(0, 0, 0);
            for ($line = 0; $line < 36; $line++) {
                $mpdf->Text(Pt::toMm(72), Pt::toMm(self::FIRST_BASELINE + self::LINE_HEIGHT * $line), $this->sentence($n, $line));
            }
        }

        $mpdf->Output($path, Destination::FILE);
    }

    private function sentence(int $page, int $line): string
    {
        $words = [];
        for ($i = 0; $i < 11; $i++) {
            $words[] = self::WORDS[($page * 7 + $line * 3 + $i * 5) % count(self::WORDS)];
        }

        return ucfirst(implode(' ', $words)).'.';
    }
}
