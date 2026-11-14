<?php

namespace Kintai\Bundles\SalaryReport\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Filtres des exports de la liste des rapports (le périmètre exportable reste contrôlé par le contrôleur).
 */
class ExportFilterRequest extends FormRequest
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
            'year' => ['nullable', 'integer', 'digits:4'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'employee_id' => ['nullable', 'integer'],
        ];
    }
}
