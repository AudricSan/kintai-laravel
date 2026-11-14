<?php

namespace Kintai\Bundles\SalaryReport\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Période à calculer (magasin, bornes, employé facultatif).
 */
class CalculateRequest extends FormRequest
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
            'store_id' => ['required', 'integer'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
            'employee_id' => ['nullable', 'integer'],
        ];
    }
}
