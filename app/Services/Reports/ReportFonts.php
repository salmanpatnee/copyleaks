<?php

namespace App\Services\Reports;

use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;

/**
 * mPDF font setup. mPDF knows only regular and bold per family, so:
 * notosans R = Regular, B = SemiBold; lexenddeca R = Medium, B = SemiBold.
 * Views use `font-weight: bold` for SemiBold.
 */
final class ReportFonts
{
    public const NOTO = 'notosans';

    public const LEXEND = 'lexenddeca';

    /** Used only for letters our fonts do not have. */
    public const BACKUP = 'dejavusans';

    /** @return array<string, mixed> */
    public function config(): array
    {
        $dirs = (new ConfigVariables)->getDefaults()['fontDir'];
        $fonts = (new FontVariables)->getDefaults()['fontdata'];

        return [
            // Added to mPDF's own list so the DejaVu backup font stays found.
            'fontDir' => array_merge([(string) config('report.fonts_dir')], $dirs),
            'fontdata' => $fonts + [
                self::NOTO => ['R' => 'NotoSans-Regular.ttf', 'B' => 'NotoSans-SemiBold.ttf'],
                self::LEXEND => ['R' => 'LexendDeca-Medium.ttf', 'B' => 'LexendDeca-SemiBold.ttf'],
            ],
            'default_font' => self::NOTO,
            'useSubstitutions' => true,
            'backupSubsFont' => [self::BACKUP],
        ];
    }
}
