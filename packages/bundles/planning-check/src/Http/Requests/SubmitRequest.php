<?php

namespace Kintai\Bundles\PlanningCheck\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Soumission ou envoi immédiat d'une vérification (confirm_empty confirme un planning vide).
 */
class SubmitRequest extends FormRequest
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
        return ['store_id' => ['required', 'integer'], 'confirm_empty' => ['nullable', 'boolean']];
    }
}
