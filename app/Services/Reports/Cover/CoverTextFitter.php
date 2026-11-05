<?php

namespace App\Services\Reports\Cover;

/**
 * Pure fitting rules for cover text. Every call takes a width function for the font that draws the
 * text, and fits against 98% of the space as a margin for fallback fonts.
 */
final class CoverTextFitter
{
    private const MARGIN = 0.98;

    /** Every run of whitespace becomes one space, and the ends are trimmed. */
    public function collapse(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * One line, cut with "…" when it is too wide. A text that already fits is not changed.
     *
     * @param  callable(string): float  $width
     */
    public function oneLine(string $text, float $space, callable $width): string
    {
        $text = $this->collapse($text);
        $limit = $space * self::MARGIN;

        if ($text === '' || $width($text) <= $limit) {
            return $text;
        }

        return $this->cut($text, $limit, $width);
    }

    /**
     * The title on at most 2 lines. Wraps at word breaks, breaks a too long word at a character,
     * and cuts the second line with "…".
     *
     * @param  callable(string): float  $width
     * @return list<string>
     */
    public function title(string $text, float $space, callable $width): array
    {
        $text = $this->collapse($text);
        $limit = $space * self::MARGIN;

        if ($text === '') {
            return [];
        }

        [$first, $rest] = $this->takeLine($text, $limit, $width);
        if ($rest === '') {
            return [$first];
        }

        return [$first, $width($rest) <= $limit ? $rest : $this->cut($rest, $limit, $width)];
    }

    /**
     * @param  callable(string): float  $width
     * @return array{0: string, 1: string} the first line and what is left
     */
    private function takeLine(string $text, float $limit, callable $width): array
    {
        if ($width($text) <= $limit) {
            return [$text, ''];
        }

        $words = explode(' ', $text);
        $line = '';
        foreach ($words as $i => $word) {
            $candidate = $line === '' ? $word : $line.' '.$word;
            if ($width($candidate) > $limit) {
                if ($line !== '') {
                    return [$line, implode(' ', array_slice($words, $i))];
                }

                // The first word alone is too wide: break it at a character.
                $length = $this->longestPrefix($word, $limit, $width);

                return [mb_substr($word, 0, $length), trim(mb_substr($word, $length).' '.implode(' ', array_slice($words, $i + 1)))];
            }
            $line = $candidate;
        }

        return [$line, ''];
    }

    /** @param  callable(string): float  $width */
    private function cut(string $text, float $limit, callable $width): string
    {
        $length = $this->longestPrefix($text, $limit, $width, '…');

        return $length === 0 ? '…' : rtrim(mb_substr($text, 0, $length)).'…';
    }

    /**
     * The most characters of `$text` (plus `$suffix`) that fit. Without a suffix it is at least 1.
     *
     * @param  callable(string): float  $width
     */
    private function longestPrefix(string $text, float $limit, callable $width, string $suffix = ''): int
    {
        $low = 0;
        $high = mb_strlen($text);
        while ($low < $high) {
            $mid = intdiv($low + $high + 1, 2);
            if ($width(rtrim(mb_substr($text, 0, $mid)).$suffix) <= $limit) {
                $low = $mid;
            } else {
                $high = $mid - 1;
            }
        }

        return $suffix === '' ? max(1, $low) : $low;
    }
}
