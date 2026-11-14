<?php

namespace Kintai\Bundles\Messaging\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtres de la liste des fils.
 */
class ThreadsRequest extends FormRequest
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
        return ['tab' => ['nullable', Rule::in(['inbox', 'sent', 'unread'])], 'store_id' => ['nullable', 'integer']];
    }
}
