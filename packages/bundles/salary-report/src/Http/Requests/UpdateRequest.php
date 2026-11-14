<?php

namespace Kintai\Bundles\SalaryReport\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Correction du contenu d'un rapport (une clé absente garde sa valeur).
 */
class UpdateRequest extends FormRequest
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
        return $this->contentRules();
    }
}
