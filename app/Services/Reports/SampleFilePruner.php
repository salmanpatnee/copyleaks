<?php

namespace App\Services\Reports;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;

/** Keeps the newest N files named `{prefix}-{Ymd}-{His}.pdf` in a folder of the local disk. Other files are never touched. */
final class SampleFilePruner
{
    public function __construct(private readonly FilesystemFactory $files) {}

    public function prune(string $dir, string $prefix, int $keep): void
    {
        $disk = $this->files->disk('local');
        $pattern = '/^'.preg_quote($prefix, '/').'-\d{8}-\d{6}\.pdf$/';
        $found = array_filter($disk->files($dir), static fn (string $f): bool => (bool) preg_match($pattern, basename($f)));
        usort($found, static fn (string $a, string $b): int => $disk->lastModified($b) <=> $disk->lastModified($a) ?: strcmp($b, $a));

        foreach (array_slice($found, max(0, $keep)) as $old) {
            $disk->delete($old);
        }
    }
}
