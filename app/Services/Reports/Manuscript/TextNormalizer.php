<?php

namespace App\Services\Reports\Manuscript;

use Normalizer;

/**
 * The shared clean up: Unicode NFKC, lower case, letters and digits only. Keeps, for every
 * cleaned character, the Copyleaks offset (UTF-16 unit) of the character it came from, also
 * when one character turns into several (a ligature, `İ`).
 */
class TextNormalizer
{
    private const LIGATURES = ['ﬀ' => 'ff', 'ﬁ' => 'fi', 'ﬂ' => 'fl', 'ﬃ' => 'ffi', 'ﬄ' => 'ffl', 'ﬅ' => 'st', 'ﬆ' => 'st'];

    /** @return list<string> the cleaned characters of a plain string (no offsets) */
    public function clean(string $text): array
    {
        $chars = [];
        foreach (mb_str_split($text) as $character) {
            foreach ($this->cleanCharacter($character) as $cleaned) {
                $chars[] = $cleaned;
            }
        }

        return $chars;
    }

    /** The whole Copyleaks text, cleaned, each cleaned character with the offset (UTF-16 unit) it came from. */
    public function cleanAll(CrawledText $text): CleanedText
    {
        $chars = [];
        $offsets = [];

        $offset = 0;
        foreach (mb_str_split($text->value) as $character) {
            foreach ($this->cleanCharacter($character) as $cleaned) {
                $chars[] = $cleaned;
                $offsets[] = $offset;
            }
            $offset += $this->unitsOf($character);
        }

        return new CleanedText($chars, $offsets);
    }

    /** @return list<string> */
    private function cleanCharacter(string $character): array
    {
        if (strlen($character) === 1) {
            return ctype_alnum($character) ? [strtolower($character)] : [];
        }

        if (class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($character, Normalizer::FORM_KC);
            $normalized = $normalized === false ? $character : $normalized;
        } else {
            $normalized = self::LIGATURES[$character] ?? $character;
        }

        $kept = preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower($normalized));

        return $kept === null || $kept === '' ? [] : mb_str_split($kept);
    }

    private function unitsOf(string $character): int
    {
        return mb_ord($character) > 0xFFFF ? 2 : 1;
    }
}
