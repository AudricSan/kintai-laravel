<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Connexion API : champs de forme (voir AuthController::login).
 */
class LoginRequest extends FormRequest
{
    /** L'autorisation est décidée par le contrôleur et l'action, comme avant. */
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
            'login' => ['required', 'string', 'max:255'],
            'store_code' => ['nullable', 'string', 'max:50'],
            'password' => ['required', 'string', 'max:1000'],
            'device_name' => ['required', 'string', 'max:255'],
            'expires_in_days' => ['nullable', 'integer', 'between:1,'.(int) config('kintai.api.token_max_days')],
            'code' => ['nullable', 'string', 'max:64'],
            'recovery_code' => ['nullable', 'string', 'max:64'],
        ];
    }
}
