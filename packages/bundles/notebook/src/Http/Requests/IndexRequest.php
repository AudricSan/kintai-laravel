<?php

namespace Kintai\Bundles\Notebook\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Filtres de la liste des notes.
 */
class IndexRequest extends FormRequest
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
            'store_id' => ['nullable', 'integer'],
            'include_expired' => ['nullable', 'boolean'],
            'pinned' => ['nullable', 'boolean'],
        ];
    }
}
