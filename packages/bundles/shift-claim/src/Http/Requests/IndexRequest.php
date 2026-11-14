<?php

namespace Kintai\Bundles\ShiftClaim\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Kintai\Bundles\ShiftClaim\Models\ShiftClaim;

/**
 * Filtres de la liste des candidatures.
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
            'shift_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(ShiftClaim::STATUSES)],
            'mine' => ['nullable', 'boolean'],
        ];
    }
}
