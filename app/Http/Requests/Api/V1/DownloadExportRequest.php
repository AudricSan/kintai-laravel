<?php

namespace App\Http\Requests\Api\V1;

use App\Exports\ExportWriter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Paramètres de téléchargement d'un export.
 */
class DownloadExportRequest extends FormRequest
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
            'format' => ['required', Rule::in(ExportWriter::FORMATS)],
            'target' => ['nullable', Rule::in(['me', 'stores'])],
            'stores' => ['nullable', 'array'],
            'stores.*' => ['integer'],
            'users' => ['nullable', 'array'],
            'users.*' => ['integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
