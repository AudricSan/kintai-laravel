<?php

namespace Kintai\Bundles\PlanningCheck\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Magasin visé par la vérification du lendemain.
 */
class StoreScopeRequest extends FormRequest
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
        return ['store_id' => ['required', 'integer']];
    }
}
