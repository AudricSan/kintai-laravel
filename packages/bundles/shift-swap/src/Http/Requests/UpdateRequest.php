<?php

namespace Kintai\Bundles\ShiftSwap\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Correction du motif d'une demande en attente.
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
        return ['reason' => ['present', 'nullable', 'string', 'max:2000']];
    }
}
