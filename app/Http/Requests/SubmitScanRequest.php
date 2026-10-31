<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitScanRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'include_ai' => ['sometimes', 'boolean'],
            'confirm_duplicate' => ['sometimes', 'boolean'],
        ];
    }

    /** The AI check is ticked by default. */
    public function includeAi(): bool
    {
        return $this->boolean('include_ai', true);
    }

    /** The override tick is never stored. */
    public function confirmDuplicate(): bool
    {
        return $this->boolean('confirm_duplicate');
    }
}
