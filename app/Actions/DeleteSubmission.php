<?php

namespace App\Actions;

use App\Exceptions\SubmissionHasRunningScan;
use App\Models\Submission;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\ConnectionInterface;

/**
 * Hard delete (spec 0002, AC-7). Rows go first in one transaction (the database
 * cascade removes the children), then the files. A leftover file is harmless
 * and the retention job sweeps it.
 */
class DeleteSubmission
{
    private const DISK = 'local';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly FilesystemFactory $files,
    ) {}

    /** @throws SubmissionHasRunningScan */
    public function handle(Submission $submission): void
    {
        $directories = $this->directoriesFor($submission);

        $this->db->transaction(function () use ($submission): void {
            // Locked read so a job cannot start a scan between the check and the delete.
            $latest = $submission->scans()->latest('id')->lockForUpdate()->first();

            if ($latest !== null && $latest->status->isRunning()) {
                throw new SubmissionHasRunningScan;
            }

            $submission->delete();
        });

        $disk = $this->files->disk(self::DISK);

        foreach ($directories as $directory) {
            $disk->deleteDirectory($directory);
        }
    }

    /** @return list<string> */
    private function directoriesFor(Submission $submission): array
    {
        $directories = ["submissions/{$submission->public_id}"];

        foreach ($submission->scans()->get(['id', 'copyleaks_scan_id']) as $scan) {
            $directories[] = "scans/{$scan->copyleaks_scan_id}";
            $directories[] = "reports/{$scan->id}";
        }

        return $directories;
    }
}
