<?php

namespace Kintai\Bundles\ShiftSwap\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Kintai\Bundles\ShiftSwap\Models\ShiftSwapRequest;

/**
 * Filtres de la liste des échanges.
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
            'status' => ['nullable', Rule::in(ShiftSwapRequest::STATUSES)],
            'stage' => ['nullable', Rule::in(['waiting_colleague', 'waiting_manager'])],
            'mine' => ['nullable', 'boolean'],
        ];
    }
}
