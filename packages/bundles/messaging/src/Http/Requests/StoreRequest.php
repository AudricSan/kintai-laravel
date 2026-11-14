<?php

namespace Kintai\Bundles\Messaging\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Kintai\Bundles\Messaging\MessagingActions;

/**
 * Création d'un fil.
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
            'subject' => ['required', 'string', 'max:'.MessagingActions::SUBJECT_MAX],
            'body' => ['required', 'string', 'max:'.MessagingActions::BODY_MAX],
            'recipient_ids' => ['required', 'array', 'min:1'],
            'recipient_ids.*' => ['integer'],
        ];
    }
}
