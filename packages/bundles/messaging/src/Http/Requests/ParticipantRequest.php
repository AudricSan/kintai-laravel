<?php

namespace Kintai\Bundles\Messaging\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Ajout d'un participant à un fil.
 */
class ParticipantRequest extends FormRequest
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
        return ['user_id' => ['required', 'integer']];
    }
}
