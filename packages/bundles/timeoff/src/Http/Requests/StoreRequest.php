<?php

namespace Kintai\Bundles\Timeoff\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Kintai\Bundles\Timeoff\Models\TimeoffRequest;

/**
 * Dépôt d'une demande de congé (pour soi, ou saisie par un gérant avec user_id).
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
            'store_id' => ['required', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'type' => ['required', Rule::in(TimeoffRequest::TYPES)],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
