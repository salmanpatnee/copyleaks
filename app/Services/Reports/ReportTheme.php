<?php

namespace App\Services\Reports;

/** Every color, size and spacing value from the design spec (docs/turnitin-report-design.md). */
final class ReportTheme
{
    public const PAGE_W = 612.0;

    public const PAGE_H = 792.0;

    public const MARGIN_X = 36.0;

    // Colors
    public const TEXT = '#191919';

    public const MUTED = '#636363';

    public const CALLOUT_TEXT = '#2d2d2d';

    public const ON_DARK = '#fefefe';

    public const DIVIDER = '#cccccc';

    public const STATS_BG = '#f8f8f8';

    public const INFO_BG = '#d0e8f9';

    // Type scale (pt)
    public const SIZE_AUTHOR = 20.0;

    public const SIZE_TITLE = 17.0;

    public const SIZE_HEADING = 10.0;

    public const SIZE_SOURCE = 8.0;

    public const SIZE_ASSIGNMENT = 8.0;

    public const SIZE_BADGE = 7.75;

    /** Badge number sizes for three digit and four or more digit ranks (spec 0011). */
    public const SIZE_BADGE_3 = 6.0;

    public const SIZE_BADGE_4 = 4.5;

    public const SIZE_BODY = 7.0;

    public const SIZE_SMALL = 6.0;

    // Font ascent as a share of the font size (text top = baseline - ascent * size)
    public const ASCENT_NOTO = 1.069;

    public const ASCENT_LEXEND = 1.0;

    // Header and footer strip
    public const LOGO_W = 55.0;

    public const LOGO_H = 16.5;

    public const HEADER_LOGO_Y = 24.0;

    public const HEADER_TEXT_TOP = 27.6;

    public const FOOTER_LOGO_FROM_BOTTOM = 44.0;

    public const FOOTER_TEXT_FROM_BOTTOM = 40.4;

    public const STRIP_LABEL_X = 107.0;

    public const STRIP_GAP = 5.0;

    // Lists and shapes
    public const ROW_H = 48.0;

    public const LIST_BOTTOM = 737.0;

    public const NAME_LIMIT_X = 358.0;

    public const PCT_X = 386.0;

    public const ROW_DIVIDER_END_X = 400.0;

    public const DIVIDER_W = 0.5;

    public const HIGHLIGHT_OPACITY = 0.8;

    public const CALLOUT_RADIUS = 8.0;

    /** Strong source color and light tint, cycled by (rank - 1) mod 4. */
    public const SOURCE_COLORS = [
        ['strong' => '#cb1476', 'tint' => '#fedce7'],
        ['strong' => '#225ec6', 'tint' => '#d6e5fb'],
        ['strong' => '#007546', 'tint' => '#c1ecdf'],
        ['strong' => '#7533e7', 'tint' => '#ecdffe'],
    ];

    /** @return array{0: int, 1: int, 2: int} */
    public static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }

    /** One CSS block for the Blade parts, because mPDF does not read CSS variables. */
    public static function css(): string
    {
        return strtr(<<<'CSS'
        body { font-family: notosans; font-size: {body}pt; line-height: 10pt; color: {text}; margin: 0; padding: 0; }
        div { margin: 0; padding: 0; }
        .abs { position: absolute; }
        .semi { font-weight: bold; }
        .muted { color: {muted}; }
        .author { font-family: lexenddeca; font-weight: bold; font-size: {author}pt; line-height: 24pt; }
        .title { font-family: lexenddeca; font-weight: normal; font-size: {title}pt; line-height: 20pt; }
        .heading { font-weight: bold; font-size: {heading}pt; line-height: 14pt; }
        .source { font-weight: bold; font-size: {source}pt; line-height: 11pt; white-space: nowrap; }
        .assignment { font-size: {assignment}pt; line-height: 10pt; color: {muted}; }
        .nowrap { white-space: nowrap; }
        .body { font-size: {body}pt; line-height: 10pt; }
        .small { font-size: {small}pt; line-height: 8pt; }
        .callout { background-color: {info}; border-radius: {radius}pt; color: {calloutText}; font-size: {small}pt; line-height: 8pt; }
        .callout-text { color: {calloutText}; }
        .stats { background-color: {stats}; color: {text}; font-weight: bold; font-size: {body}pt; }
        .divider { background-color: {divider}; height: {dividerW}pt; font-size: 1pt; line-height: 1pt; }
        .pill { color: {onDark}; text-align: center; font-weight: bold; font-size: {body}pt; line-height: 15pt; border-radius: 7pt; }
        .pill-tint { color: {text}; text-align: center; font-weight: bold; font-size: {body}pt; line-height: 15pt; border-radius: 7.5pt; }
        CSS, [
            '{body}' => (string) self::SIZE_BODY,
            '{text}' => self::TEXT,
            '{muted}' => self::MUTED,
            '{author}' => (string) self::SIZE_AUTHOR,
            '{title}' => (string) self::SIZE_TITLE,
            '{heading}' => (string) self::SIZE_HEADING,
            '{source}' => (string) self::SIZE_SOURCE,
            '{assignment}' => (string) self::SIZE_ASSIGNMENT,
            '{small}' => (string) self::SIZE_SMALL,
            '{info}' => self::INFO_BG,
            '{radius}' => (string) self::CALLOUT_RADIUS,
            '{calloutText}' => self::CALLOUT_TEXT,
            '{stats}' => self::STATS_BG,
            '{divider}' => self::DIVIDER,
            '{dividerW}' => (string) self::DIVIDER_W,
            '{onDark}' => self::ON_DARK,
        ]);
    }
}
