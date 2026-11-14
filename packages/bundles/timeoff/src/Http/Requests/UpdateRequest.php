<?php

namespace Kintai\Bundles\Timeoff\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Kintai\Bundles\Timeoff\Models\TimeoffRequest;

/**
 * Correction d'une demande de congé ; une clé absente garde sa valeur. La cohérence des dates avec la demande enregistrée reste vérifiée par le contrôleur.
 */
class UpdateRequest extends FormRequest
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
            'type' => ['sometimes', Rule::in(TimeoffRequest::TYPES)],
            'start_date' => ['sometimes', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'date_format:Y-m-d'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'admin_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
