<?php

namespace App\Services\Reports\Mapping;

use App\Exceptions\ReportRenderFailed;
use App\Services\Copyleaks\ScanSummary;

/** Turns the summary items into ranked sources with percent, category and name (AC-4 to AC-7). */
class SourceListBuilder
{
    public function __construct(private SourceNamer $namer) {}

    /** @return list<ListedSource> ranked 1 to n, most matched words first */
    public function build(ScanSummary $summary, int $totalWords): array
    {
        $kept = [];

        foreach ($summary->resultItems() as $position => ['group' => $group, 'item' => $item]) {
            if (! is_array($item)) {
                throw ReportRenderFailed::summaryUnreadable();
            }

            $words = WholeNumber::from($item['matchedWords'] ?? null);

            if ($words === null || $words < 0) {
                throw ReportRenderFailed::summaryUnreadable();
            }

            if ($words === 0) {
                continue;
            }

            $id = $item['id'] ?? null;

            $kept[] = [
                'position' => $position,
                'id' => is_string($id) && $id !== '' ? $id : null,
                'words' => $words,
                'category' => $group === 'internet' ? 'Internet' : null,
                'name' => $this->namer->name($item),
            ];
        }

        usort($kept, fn (array $a, array $b): int => [$b['words'], $a['position']] <=> [$a['words'], $b['position']]);

        $sources = [];

        foreach ($kept as $index => $source) {
            $sources[] = new ListedSource(
                rank: $index + 1,
                id: $source['id'],
                matchedWords: $source['words'],
                percent: min(100.0, $source['words'] / $totalWords * 100),
                category: $source['category'],
                name: $source['name'],
            );
        }

        return $sources;
    }
}
