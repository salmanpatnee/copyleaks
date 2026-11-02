<?php

namespace App\Services\Copyleaks;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Saves a Copyleaks body exactly as received, gzip compressed, on the private disk.
 *
 * The body is never parsed or changed. It is written to a temp name and renamed
 * into place, so a half written file is never seen. A file already there is kept
 * (raw files are insert only) and a missing one is written again.
 */
class RawFileWriter
{
    /** @return array{path: string, sha256: string, size: int} hash and size are of the compressed file */
    public function store(string $path, string $rawBody): array
    {
        $disk = Storage::disk('local');

        if ($disk->exists($path)) {
            $bytes = (string) $disk->get($path);
        } else {
            $bytes = (string) gzencode($rawBody, 9);
            $temp = $path.'.'.Str::random(8).'.tmp';
            $disk->put($temp, $bytes);

            try {
                $disk->move($temp, $path);
            } catch (\Throwable) {
                // Another call won the race and the target now exists. Keep theirs.
                $disk->delete($temp);
            }
        }

        return ['path' => $path, 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes)];
    }
}
