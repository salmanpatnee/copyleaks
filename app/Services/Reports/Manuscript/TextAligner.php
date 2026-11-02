<?php

namespace App\Services\Reports\Manuscript;

/**
 * Matches the cleaned Copyleaks characters to the cleaned PDF characters. First every PDF page is
 * anchored inside the whole Copyleaks text (`PageAnchorer`, AC-13), so one bad page cannot shift the
 * rest. Then each page is walked together with its region, in content stream order. On a mismatch it
 * looks for the nearest point where the next 6 characters agree again (at most 64 characters skipped
 * in total) and takes the smallest skip.
 */
class TextAligner
{
    private const RESYNC_RUN = 6;

    private const RESYNC_BUDGET = 64;

    public function __construct(
        private readonly TextNormalizer $normalizer,
        private readonly PageAnchorer $anchorer,
    ) {}

    /** @param  list<PdfPageWords>  $pages */
    public function align(CrawledText $text, array $pages): Alignment
    {
        $copyleaks = $this->normalizer->cleanAll($text);
        $streams = array_map($this->pdfStream(...), $pages);
        $placed = $this->anchorer->anchor($copyleaks, array_map(static fn (array $s): array => $s['chars'], $streams));
        $anchors = $placed['anchors'];

        $found = [];
        $matchedByPage = [];
        $matched = 0;
        $order = array_keys($anchors);

        foreach ($order as $position => $page) {
            $end = isset($order[$position + 1]) ? $anchors[$order[$position + 1]] : $copyleaks->length();
            $following = $order[$position + 1] ?? count($pages);
            $joined = array_values(array_filter($placed['short'], static fn (int $s): bool => $s > $page && $s < $following));

            $matched += $this->walk($copyleaks, $anchors[$page], $end, $this->region($streams, $page, $joined), $found, $matchedByPage);
        }

        $total = $copyleaks->length();
        $shares = [];
        foreach ($streams as $index => $stream) {
            if (count($stream['chars']) >= 8) {
                $shares[$index + 1] = ($matchedByPage[$index] ?? 0) / count($stream['chars']);
            }
        }

        return new Alignment($found, $copyleaks->offsets, $total === 0 ? 0.0 : $matched / $total, $shares);
    }

    /**
     * @return array{chars: list<string>, words: list<int>} cleaned PDF characters and the word each belongs to (-1 for a word with a bad box)
     */
    private function pdfStream(PdfPageWords $page): array
    {
        $chars = [];
        $words = [];

        foreach ($page->words as $index => $word) {
            $valid = $word->width > 0
                && $word->x >= 0 && $word->y >= 0
                && $word->x + $word->width <= $page->box->width() + 0.5
                && $word->y + $word->height <= $page->box->height() + 0.5;
            foreach (mb_str_split($word->chars) as $character) {
                $chars[] = $character;
                $words[] = $valid ? $index : -1;
            }
        }

        return ['chars' => $chars, 'words' => $words];
    }

    /**
     * One page plus the short pages that join it, as one stream that remembers each character's page.
     *
     * @param  list<array{chars: list<string>, words: list<int>}>  $streams
     * @param  list<int>  $joined
     * @return array{chars: list<string>, words: list<int>, pages: list<int>}
     */
    private function region(array $streams, int $page, array $joined): array
    {
        $region = ['chars' => [], 'words' => [], 'pages' => []];
        foreach ([$page, ...$joined] as $index) {
            array_push($region['chars'], ...$streams[$index]['chars']);
            array_push($region['words'], ...$streams[$index]['words']);
            array_push($region['pages'], ...array_fill(0, count($streams[$index]['chars']), $index));
        }

        return $region;
    }

    /**
     * @param  array{chars: list<string>, words: list<int>, pages: list<int>}  $pdf
     * @param  array<int, array{0: int, 1: int}>  $found
     * @param  array<int, int>  $matchedByPage
     * @return int how many Copyleaks characters were matched in this region
     */
    private function walk(CleanedText $cl, int $from, int $to, array $pdf, array &$found, array &$matchedByPage): int
    {
        $chars = $cl->chars;
        $i = $from;
        $j = 0;
        $matched = 0;
        $m = count($pdf['chars']);

        while ($i < $to && $j < $m) {
            if ($chars[$i] === $pdf['chars'][$j]) {
                if ($pdf['words'][$j] >= 0) {
                    $found[$cl->offsets[$i]] = [$pdf['pages'][$j], $pdf['words'][$j]];
                    $matchedByPage[$pdf['pages'][$j]] = ($matchedByPage[$pdf['pages'][$j]] ?? 0) + 1;
                    $matched++;
                }
                $i++;
                $j++;

                continue;
            }

            $skip = $this->resync($chars, $pdf['chars'], $i, $to, $j);
            if ($skip === null) {
                $i++;
            } else {
                $i += $skip[0];
                $j += $skip[1];
            }
        }

        return $matched;
    }

    /**
     * Smallest total skip (a in the Copyleaks stream, b in the PDF stream) after which the next
     * characters agree again. Null when there is none inside the budget.
     *
     * @param  list<string>  $cl
     * @param  list<string>  $pdf
     * @return array{0: int, 1: int}|null
     */
    private function resync(array $cl, array $pdf, int $i, int $to, int $j): ?array
    {
        $m = count($pdf);

        for ($d = 1; $d <= self::RESYNC_BUDGET; $d++) {
            for ($a = 0; $a <= $d; $a++) {
                $b = $d - $a;
                if ($i + $a >= $to || $j + $b >= $m) {
                    continue;
                }
                $need = min(self::RESYNC_RUN, $to - $i - $a);
                if ($m - $j - $b < $need) {
                    continue;
                }
                for ($k = 0; $k < $need; $k++) {
                    if ($cl[$i + $a + $k] !== $pdf[$j + $b + $k]) {
                        continue 2;
                    }
                }

                return [$a, $b];
            }
        }

        return null;
    }
}
