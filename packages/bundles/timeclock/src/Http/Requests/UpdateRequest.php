<?php

namespace Kintai\Bundles\Timeclock\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Correction des heures et de la note d'un pointage.
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
            'clock_in_at' => ['sometimes', 'required', 'date'],
            'clock_out_at' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
