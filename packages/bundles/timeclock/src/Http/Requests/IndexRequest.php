<?php

namespace Kintai\Bundles\Timeclock\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Filtres de la liste des pointages.
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
            'user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'active' => ['nullable', 'boolean'],
        ];
    }
}
