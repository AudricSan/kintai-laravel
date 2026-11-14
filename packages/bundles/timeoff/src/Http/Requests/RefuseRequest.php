<?php

namespace Kintai\Bundles\Timeoff\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Refus d'une demande de congé, avec une note facultative.
 */
class RefuseRequest extends FormRequest
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
        return ['admin_note' => ['nullable', 'string', 'max:2000']];
    }
}
