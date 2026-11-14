<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Préférences de navigation : clés masquées et barre du bas.
 */
class UpdateNavigationPrefsRequest extends FormRequest
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
            'hidden' => ['nullable', 'array'],
            'hidden.*' => ['string', 'max:100'],
            'bottom' => ['nullable', 'array'],
            'bottom.*' => ['string', 'max:100'],
        ];
    }
}
