<?php

namespace App\Services\Reports\Mapping;

/** The name shown for a source: host of its url, else its title, else a fixed text (AC-7). */
class SourceNamer
{
    public const FALLBACK = 'Untitled source';

    /** @param  array<mixed>  $item */
    public function name(array $item): string
    {
        $url = $item['url'] ?? null;
        $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;

        foreach ([$host, $item['title'] ?? null] as $candidate) {
            $name = is_string($candidate) ? $this->clean($candidate) : '';

            if ($name !== '') {
                return $name;
            }
        }

        return self::FALLBACK;
    }

    private function clean(string $value): string
    {
        $stripped = preg_replace('/\p{Cc}/u', '', $value) ?? '';

        return trim($stripped);
    }
}
