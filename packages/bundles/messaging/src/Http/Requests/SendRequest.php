<?php

namespace Kintai\Bundles\Messaging\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Kintai\Bundles\Messaging\MessagingActions;

/**
 * Envoi d'un message dans un fil.
 */
class SendRequest extends FormRequest
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
        return ['body' => ['required', 'string', 'max:'.MessagingActions::BODY_MAX]];
    }
}
