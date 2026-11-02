<?php

namespace App\Services\Copyleaks;

use App\Enums\ArtifactKind;
use App\Models\Scan;
use Illuminate\Support\Facades\Storage;

/** Loads the saved `summary` artifact of a scan. Null when the row or file is missing or unreadable. */
class ScanSummaryReader
{
    public function read(Scan $scan): ?ScanSummary
    {
        $path = $scan->artifacts()
            ->where('kind', ArtifactKind::Summary->value)
            ->where('source_id', '')
            ->value('path');

        if (! is_string($path) || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        $json = gzdecode((string) Storage::disk('local')->get($path));
        $data = $json === false ? null : json_decode($json, true);

        return is_array($data) ? new ScanSummary($data) : null;
    }
}
