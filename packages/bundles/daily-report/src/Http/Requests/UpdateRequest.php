<?php

namespace Kintai\Bundles\DailyReport\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Correction d'un rapport journalier (une clé absente garde sa valeur).
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
        return $this->contentRules(false);
    }
}
