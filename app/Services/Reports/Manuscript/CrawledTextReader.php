<?php

namespace App\Services\Reports\Manuscript;

use App\Enums\ArtifactKind;
use App\Models\Scan;
use Illuminate\Support\Facades\Storage;

/** Loads the saved `text` artifact of a scan. Null when the row or file is missing or is not valid JSON. */
class CrawledTextReader
{
    public function read(Scan $scan): ?CrawledText
    {
        $path = $scan->artifacts()
            ->where('kind', ArtifactKind::Text->value)
            ->where('source_id', '')
            ->value('path');

        if (! is_string($path) || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        $json = gzdecode((string) Storage::disk('local')->get($path));
        $data = $json === false ? null : json_decode($json, true);
        $text = is_array($data) ? ($data['text'] ?? null) : null;

        if (! is_array($text) || ! is_string($text['value'] ?? null)) {
            return null;
        }

        return CrawledText::make($text['value']);
    }
}
