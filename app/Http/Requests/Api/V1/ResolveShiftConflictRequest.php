<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Résolution d'un conflit : les deux shifts.
 */
class ResolveShiftConflictRequest extends FormRequest
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
            'first_id' => ['required', 'integer'],
            'second_id' => ['required', 'integer'],
        ];
    }
}
