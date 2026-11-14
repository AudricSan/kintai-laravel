<?php

namespace Kintai\Bundles\SalaryReport\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Création d'un rapport : magasin, mois, employé facultatif, puis le contenu.
 */
class StoreRequest extends FormRequest
{
    use ReportContent;

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
            'month' => ['required', 'date_format:Y-m'],
            'employee_id' => ['nullable', 'integer'],
        ] + $this->contentRules();
    }
}
