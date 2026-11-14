<?php

namespace Kintai\Bundles\SalaryReport\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Filtres de la liste des rapports de salaire.
 */
class IndexRequest extends FormRequest
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
            'store_id' => ['nullable', 'integer'],
            'employee_id' => ['nullable', 'integer'],
            'month' => ['nullable', 'date_format:Y-m'],
            'year' => ['nullable', 'integer', 'digits:4'],
        ];
    }
}
