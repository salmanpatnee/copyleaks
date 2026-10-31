<?php

namespace App\Http\Requests;

use App\Exceptions\ManuscriptRejected;
use App\Services\Manuscripts\InspectedManuscript;
use App\Services\Manuscripts\ManuscriptInspector;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;
use LogicException;

class StoreSubmissionRequest extends FormRequest
{
    private ?InspectedManuscript $inspected = null;

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'file' => ['bail', 'required', 'file', 'extensions:pdf,docx', 'max:20480'],
            'author' => ['required', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'assignment' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.required' => 'Please choose a manuscript file.',
            'file.file' => 'The file did not upload correctly. Please try again.',
            'file.uploaded' => 'File is too large or did not finish uploading.',
            'file.extensions' => 'Only PDF (.pdf) and Word (.docx) files are allowed.',
            'file.max' => 'The file is too big. The limit is 20 MB.',
        ];
    }

    /**
     * Inspect the file after the basic rules pass, so a bad file is refused before anything is written.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(ManuscriptInspector $inspector): array
    {
        return [
            function (Validator $validator) use ($inspector): void {
                $file = $this->file('file');

                if (! $file instanceof UploadedFile || $validator->errors()->has('file')) {
                    return;
                }

                try {
                    $this->inspected = $inspector->inspect((string) $file->getRealPath());
                } catch (ManuscriptRejected $e) {
                    $validator->errors()->add('file', $e->getMessage());
                }
            },
        ];
    }

    /** The result of the single inspection, reused by the store action. */
    public function inspected(): InspectedManuscript
    {
        return $this->inspected ?? throw new LogicException('The file has not been inspected yet.');
    }

    public function manuscript(): UploadedFile
    {
        $file = $this->file('file');

        return $file instanceof UploadedFile ? $file : throw new LogicException('No file on the request.');
    }
}
