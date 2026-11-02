<?php

namespace App\Actions;

use App\Enums\ArtifactKind;
use App\Models\Scan;
use App\Models\ScanArtifact;
use App\Services\Copyleaks\RawFileWriter;
use Illuminate\Support\Facades\Log;

/**
 * Saves one pushed export file exactly as received (spec 0005, AC-3, AC-4).
 * Nothing is parsed, trimmed or re-encoded. Calls nothing at Copyleaks.
 * A repeat changes nothing, and rewrites the file only if it is missing from disk.
 */
class AcceptExportPush
{
    public function __construct(private readonly RawFileWriter $writer) {}

    /** @param  string|null  $resultId  null for the crawled text */
    public function __invoke(Scan $scan, string $exportId, ?string $resultId, string $rawBody): void
    {
        $name = $resultId === null ? 'text' : "result-{$resultId}";
        $file = $this->writer->store("scans/{$scan->copyleaks_scan_id}/{$name}.json.gz", $rawBody);

        ScanArtifact::query()->insertOrIgnore([[
            'scan_id' => $scan->getKey(),
            'kind' => ($resultId === null ? ArtifactKind::Text : ArtifactKind::Result)->value,
            'source_id' => $resultId ?? '',
            'path' => $file['path'],
            'sha256' => $file['sha256'],
            'size_bytes' => $file['size'],
            'created_at' => now(),
        ]]);

        Log::info('Copyleaks export file saved.', [
            'scan_id' => $scan->copyleaks_scan_id,
            'export_id' => $exportId,
            'part' => $resultId === null ? 'text' : 'result',
        ]);
    }
}
