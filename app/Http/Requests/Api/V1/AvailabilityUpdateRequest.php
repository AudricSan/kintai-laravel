<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Disponibilités de la semaine (la validité des jours 1 à 7 reste vérifiée par le contrôleur).
 */
class AvailabilityUpdateRequest extends FormRequest
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
            'days' => ['required', 'array'],
            'days.*' => ['array'],
        ];
    }
}
