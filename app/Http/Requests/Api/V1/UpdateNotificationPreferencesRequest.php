<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Préférences de notification : forme seulement (les types inconnus sont refusés par le contrôleur).
 */
class UpdateNotificationPreferencesRequest extends FormRequest
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
            'preferences' => ['nullable', 'array'],
            'preferences.*' => ['array'],
            'preferences.*.push' => ['boolean'],
            'preferences.*.mail' => ['boolean'],
            'quiet_hours' => ['nullable', 'array'],
            'quiet_hours.enabled' => ['required_with:quiet_hours', 'boolean'],
            'quiet_hours.start' => ['nullable', 'string', 'max:8'],
            'quiet_hours.end' => ['nullable', 'string', 'max:8'],
        ];
    }
}
