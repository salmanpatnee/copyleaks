<?php

namespace App\Services;

use App\Enums\ReportKind;
use App\Models\Report;
use App\Models\Scan;
use Closure;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * Safe report rebuild (spec 0002, AC-12): write the new PDF first, then switch
 * the row, then delete the old file. A failed build leaves the old report alone.
 */
class ReportReplacer
{
    private const DISK = 'local';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly FilesystemFactory $files,
    ) {}

    /**
     * @param  Closure(): BuiltReport  $build  writes the new PDF to a new path and describes it
     */
    public function replace(Scan $scan, ReportKind $kind, Closure $build): Report
    {
        $built = $build();

        try {
            [$report, $oldPath] = $this->db->transaction(function () use ($scan, $kind, $built): array {
                $old = Report::query()->where('scan_id', $scan->id)->where('kind', $kind->value)->first();

                $report = Report::query()->updateOrCreate(
                    ['scan_id' => $scan->id, 'kind' => $kind->value],
                    [
                        'path' => $built->path,
                        'sha256' => $built->sha256,
                        'size_bytes' => $built->sizeBytes,
                        'page_count' => $built->pageCount,
                        'renderer_version' => $built->rendererVersion,
                        'built_at' => now(),
                    ],
                );

                return [$report, $old?->path];
            });
        } catch (Throwable $e) {
            // The row did not switch, so the new file is an orphan. Remove it and keep the old report.
            $this->files->disk(self::DISK)->delete($built->path);

            throw $e;
        }

        if ($oldPath !== null && $oldPath !== $built->path) {
            $this->files->disk(self::DISK)->delete($oldPath);
        }

        return $report;
    }
}
