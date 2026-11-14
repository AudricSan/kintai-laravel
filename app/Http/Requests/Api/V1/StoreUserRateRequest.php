<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Création d'un tarif personnel (l'unicité par type reste vérifiée par le contrôleur).
 */
class StoreUserRateRequest extends FormRequest
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
            'shift_type_id' => ['required', 'integer'],
            'hourly_rate' => ['required', 'numeric'],
        ];
    }
}
