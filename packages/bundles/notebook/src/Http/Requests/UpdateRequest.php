<?php

namespace Kintai\Bundles\Notebook\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Correction d'une note.
 */
class UpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'content' => ['sometimes', 'required', 'string', 'max:5000'],
            'expires_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }
}
