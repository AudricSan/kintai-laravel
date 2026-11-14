<?php

namespace Kintai\Bundles\ShiftClaim\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Filtre des shifts ouverts.
 */
class OpenShiftsRequest extends FormRequest
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
        return ['store_id' => ['nullable', 'integer']];
    }
}
