<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Filtres de la liste des types de shift.
 */
class ShiftTypeIndexRequest extends FormRequest
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
            'store_id' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
