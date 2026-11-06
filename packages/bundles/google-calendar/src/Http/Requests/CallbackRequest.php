<?php

namespace Kintai\Bundles\GoogleCalendar\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Retour de Google après l'autorisation : `state` et `code` (autorisation donnée) ou `error` (refusée). Une requête
 * mal formée n'affiche pas d'erreur de validation : la personne est ramenée à sa page avec un message.
 */
class CallbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'state' => ['nullable', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:2048'],
            'error' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            redirect()->route('google-calendar.profile')->with('google_calendar_flash', ['variant' => 'danger', 'text' => __('The connection request has expired or is not valid. Start again from your profile.')]),
        );
    }
}
