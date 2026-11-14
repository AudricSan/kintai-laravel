<?php

namespace Kintai\Bundles\ShiftSwap\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Échange appliqué d'emblée par un gérant (force confirme les avertissements du planning).
 */
class ApplyRequest extends FormRequest
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
            'requester_shift_id' => ['required', 'integer'],
            'target_shift_id' => ['required', 'integer'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'force' => ['nullable', 'boolean'],
        ];
    }
}
