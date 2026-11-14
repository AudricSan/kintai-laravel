<?php

namespace Kintai\Bundles\Notebook\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Création d'une note.
 */
class StoreRequest extends FormRequest
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
            'content' => ['required', 'string', 'max:5000'],
            'store_id' => ['nullable', 'integer'],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
