<?php

namespace App\Services\Reports\Mapping;

use App\Enums\ArtifactKind;
use App\Exceptions\ReportRenderFailed;
use App\Models\Scan;
use Closure;
use Illuminate\Support\Facades\Storage;

/**
 * The only code that opens the saved `summary` and `result` files for reports (spec 0008, AC-2).
 * It takes a kind and a source ID, never a path. Every failure is a plain reason with no file text.
 */
class ScanBundleReader
{
    /** @return array<mixed> */
    public function summary(Scan $scan): array
    {
        return $this->read(
            $scan,
            ArtifactKind::Summary,
            '',
            ReportRenderFailed::summaryMissing(...),
            ReportRenderFailed::summaryUnreadable(...),
        );
    }

    /** @return array<mixed> */
    public function result(Scan $scan, string $sourceId): array
    {
        return $this->read(
            $scan,
            ArtifactKind::Result,
            $sourceId,
            ReportRenderFailed::resultMissing(...),
            ReportRenderFailed::resultUnreadable(...),
        );
    }

    /**
     * @param  Closure(): ReportRenderFailed  $missing
     * @param  Closure(): ReportRenderFailed  $unreadable
     * @return array<mixed>
     */
    private function read(Scan $scan, ArtifactKind $kind, string $sourceId, Closure $missing, Closure $unreadable): array
    {
        $row = $scan->artifacts()
            ->where('kind', $kind->value)
            ->where('source_id', $sourceId)
            ->first(['path', 'sha256']);

        $disk = Storage::disk('local');

        if ($row === null || ! $disk->exists($row->path)) {
            throw $missing();
        }

        $bytes = (string) $disk->get($row->path);

        if (! hash_equals($row->sha256, hash('sha256', $bytes))) {
            throw ReportRenderFailed::checksumMismatch();
        }

        $json = @gzdecode($bytes);
        unset($bytes);

        $data = $json === false ? null : json_decode($json, true);
        unset($json);

        if (! is_array($data)) {
            throw $unreadable();
        }

        return $data;
    }
}
