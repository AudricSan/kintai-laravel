<?php

namespace Kintai\Bundles\ShiftClaim\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Candidature ou proposition de shift, avec une note facultative.
 */
class NoteRequest extends FormRequest
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
        return ['note' => ['nullable', 'string', 'max:2000']];
    }
}
