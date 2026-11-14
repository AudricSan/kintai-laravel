<?php

namespace Kintai\Bundles\ShiftSwap\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Proposition d'un échange de shifts.
 */
class StoreRequest extends FormRequest
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
        ];
    }
}
