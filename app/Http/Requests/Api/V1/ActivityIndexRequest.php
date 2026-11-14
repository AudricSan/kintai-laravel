<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Filtres du journal d'activité.
 */
class ActivityIndexRequest extends FormRequest
{
    /** L'autorisation est décidée par le contrôleur et l'action, comme avant. */
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
            'action' => ['nullable', 'string', 'max:100'],
            'actor' => ['nullable', 'integer'],
            'store' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
