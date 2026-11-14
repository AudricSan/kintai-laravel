<?php

namespace Kintai\Bundles\Timeoff\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Kintai\Bundles\Timeoff\Models\TimeoffRequest;

/**
 * Filtres de la liste des demandes de congé.
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
            'user_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(TimeoffRequest::STATUSES)],
            'type' => ['nullable', Rule::in(TimeoffRequest::TYPES)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
