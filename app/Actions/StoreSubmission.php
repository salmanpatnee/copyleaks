<?php

namespace App\Actions;

use App\Exceptions\ManuscriptStorageFailed;
use App\Models\Submission;
use App\Services\Manuscripts\InspectedManuscript;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Saves the file on the private disk, then one submissions row. Never calls Copyleaks. */
class StoreSubmission
{
    /** @param  array{author: string, title: string, assignment: string}  $details */
    public function __invoke(UploadedFile $file, InspectedManuscript $inspected, array $details): Submission
    {
        $publicId = (new Submission)->newUniqueId();
        $folder = "submissions/{$publicId}";
        $name = "manuscript.{$inspected->type->value}";

        $sha256 = (string) hash_file('sha256', (string) $file->getRealPath());
        $size = (int) $file->getSize();

        $disk = Storage::disk('local');

        if ($disk->putFileAs($folder, $file, $name) === false) {
            throw new ManuscriptStorageFailed;
        }

        try {
            $submission = new Submission([
                ...$details,
                'user_id' => null,
                'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
                'file_path' => "{$folder}/{$name}",
                'file_size' => $size,
                'file_sha256' => $sha256,
                'page_count' => $inspected->pageCount,
            ]);
            $submission->forceFill(['public_id' => $publicId])->save();
        } catch (Throwable $e) {
            $disk->deleteDirectory($folder);

            throw $e;
        }

        return $submission;
    }
}
