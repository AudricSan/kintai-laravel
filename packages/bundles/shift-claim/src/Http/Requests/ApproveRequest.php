<?php

namespace Kintai\Bundles\ShiftClaim\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Approbation d'une candidature (force confirme les avertissements du planning).
 */
class ApproveRequest extends FormRequest
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
        return ['force' => ['nullable', 'boolean']];
    }
}
