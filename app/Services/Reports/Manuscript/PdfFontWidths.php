<?php

namespace App\Services\Reports\Manuscript;

use ReflectionProperty;
use Smalot\PdfParser\Element;
use Smalot\PdfParser\Font;
use Smalot\PdfParser\PDFObject;

/**
 * Glyph widths for the fonts of one file, in thousandths of an em. Read once per font and cached.
 * Simple fonts use `FirstChar` and `Widths`; Type0 fonts use the descendant font's `W` and `DW`.
 * A glyph with no width gives null.
 */
class PdfFontWidths
{
    /** @var array<string, array<string, float>> font key => character => width */
    private array $cache = [];

    public function width(Font $font, string $fontKey, string $character): ?float
    {
        if (! isset($this->cache[$fontKey])) {
            $this->cache[$fontKey] = $this->load($font);
        }

        return $this->cache[$fontKey][$character] ?? null;
    }

    /** @return array<string, float> */
    private function load(Font $font): array
    {
        $isCid = $font->getType() === 'Type0';
        $widthByCode = $isCid ? $this->cidWidths($font) : $this->simpleWidths($font);
        $default = $isCid ? $this->defaultWidth($font) : null;

        $map = [];
        foreach ($this->table($font) as $code => $character) {
            $width = $widthByCode[(int) $code] ?? $default;
            if ($width !== null && ! isset($map[$character])) {
                $map[$character] = $width;
            }
        }

        return $map;
    }

    /** @return array<int|string, string> code => character, as smalot decoded it */
    private function table(Font $font): array
    {
        $table = (new ReflectionProperty(Font::class, 'table'))->getValue($font);

        return is_array($table) ? $table : [];
    }

    /** @return array<int, float> */
    private function simpleWidths(Font $font): array
    {
        $details = $font->getDetails();
        $first = (int) ($details['FirstChar'] ?? 0);
        $out = [];
        foreach ((array) ($details['Widths'] ?? []) as $index => $width) {
            if (is_numeric($width)) {
                $out[$first + (int) $index] = (float) $width;
            }
        }

        return $out;
    }

    private function descendant(Font $font): ?PDFObject
    {
        $fonts = $font->get('DescendantFonts');
        $list = match (true) {
            $fonts instanceof PDFObject => $fonts->getHeader()?->getElements(),
            $fonts instanceof Element => $fonts->getContent(),
            default => null,
        };
        $first = is_array($list) ? ($list[0] ?? null) : null;

        return $first instanceof PDFObject ? $first : null;
    }

    private function defaultWidth(Font $font): float
    {
        $dw = $this->descendant($font)?->getHeader()?->get('DW');
        $content = $dw instanceof Element ? $dw->getContent() : null;

        return is_numeric($content) ? (float) $content : 1000.0;
    }

    /**
     * Reads the `W` array: `c [w1 w2 ...]` gives widths for consecutive CIDs, `c1 c2 w` one width for a range.
     *
     * @return array<int, float>
     */
    private function cidWidths(Font $font): array
    {
        $w = $this->descendant($font)?->getHeader()?->get('W');
        // `W` is either an inline array, or an indirect object whose elements sit in its header.
        $items = match (true) {
            $w instanceof Element => $w->getContent(),
            $w instanceof PDFObject => $w->getHeader()?->getElements(),
            default => null,
        };
        if (! is_array($items)) {
            return [];
        }

        $flat = array_map(static fn ($i) => $i instanceof Element ? $i->getContent() : $i, array_values($items));
        $out = [];
        $i = 0;
        $count = count($flat);
        while ($i < $count) {
            $start = $flat[$i];
            $next = $flat[$i + 1] ?? null;
            if (! is_numeric($start)) {
                $i++;

                continue;
            }
            if (is_array($next)) {
                foreach (array_values($next) as $offset => $width) {
                    $value = $width instanceof Element ? $width->getContent() : $width;
                    if (is_numeric($value)) {
                        $out[(int) $start + $offset] = (float) $value;
                    }
                }
                $i += 2;
            } elseif (is_numeric($next) && is_numeric($flat[$i + 2] ?? null)) {
                for ($cid = (int) $start; $cid <= (int) $next; $cid++) {
                    $out[$cid] = (float) $flat[$i + 2];
                }
                $i += 3;
            } else {
                $i++;
            }
        }

        return $out;
    }
}
