<?php

namespace App\Services\Copyleaks;

use Illuminate\Support\Arr;

/**
 * Reads the values the export and finish steps need from the saved `completed`
 * summary (spec 0005, Value sourcing). It only reads. The raw file is never changed.
 */
final readonly class ScanSummary
{
    private const RESULT_GROUPS = ['internet', 'database', 'batch', 'repositories'];

    /** @param  array<string, mixed>  $data */
    public function __construct(private array $data) {}

    /** @return list<string> */
    public function resultIds(): array
    {
        $ids = [];

        foreach (self::RESULT_GROUPS as $group) {
            foreach ((array) Arr::get($this->data, "results.{$group}", []) as $item) {
                $id = is_array($item) ? ($item['id'] ?? null) : null;

                if (is_string($id) && $id !== '') {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Every item of the four result groups, in the group order internet, database, batch, repositories.
     * An item whose non empty `id` already appeared is dropped, like `resultIds()` does. Items without an id are all kept.
     *
     * @return list<array{group: string, item: mixed}>
     */
    public function resultItems(): array
    {
        $items = [];
        $seen = [];

        foreach (self::RESULT_GROUPS as $group) {
            foreach (array_values((array) Arr::get($this->data, "results.{$group}", [])) as $item) {
                $id = is_array($item) ? ($item['id'] ?? null) : null;

                if (is_string($id) && $id !== '') {
                    if (isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;
                }

                $items[] = ['group' => $group, 'item' => $item];
            }
        }

        return $items;
    }

    /** `scannedDocument.totalWords` as a whole number. Null when missing or not a whole number. */
    public function totalWords(): ?int
    {
        $words = Arr::get($this->data, 'scannedDocument.totalWords');

        if (is_float($words) && $words === floor($words) && abs($words) < PHP_INT_MAX) {
            return (int) $words;
        }

        return is_int($words) ? $words : null;
    }

    /** `results.score.aggregatedScore`, already a percent. Null when missing or not a number. */
    public function similarityPercent(): ?float
    {
        $score = Arr::get($this->data, 'results.score.aggregatedScore');

        return is_int($score) || is_float($score) ? round((float) $score, 2) : null;
    }

    /** The `Ai` value times 100 from the `suspected-ai-text` alert. Null when absent or unreadable (AC-8). */
    public function aiPercent(): ?float
    {
        foreach ((array) Arr::get($this->data, 'notifications.alerts', []) as $alert) {
            if (! is_array($alert) || ($alert['code'] ?? null) !== 'suspected-ai-text') {
                continue;
            }

            $extra = $alert['additionalData'] ?? null;
            $decoded = is_string($extra) ? json_decode($extra, true) : null;

            if (! is_array($decoded)) {
                return null;
            }

            $value = $this->findAi(is_array($decoded['summary'] ?? null) ? $decoded['summary'] : $decoded);

            return $value === null ? null : round($value * 100, 2);
        }

        return null;
    }

    /** @param  array<mixed>  $values */
    private function findAi(array $values): ?float
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && strtolower($key) === 'ai' && (is_int($value) || is_float($value))) {
                return (float) $value;
            }
        }

        return null;
    }

    public function hasAiAlert(): bool
    {
        foreach ((array) Arr::get($this->data, 'notifications.alerts', []) as $alert) {
            if (is_array($alert) && ($alert['code'] ?? null) === 'suspected-ai-text') {
                return true;
            }
        }

        return false;
    }
}
