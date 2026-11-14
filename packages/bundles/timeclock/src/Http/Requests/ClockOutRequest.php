<?php

namespace Kintai\Bundles\Timeclock\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pointage de départ : heure du client facultative.
 */
class ClockOutRequest extends FormRequest
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
        return ['client_time' => ['nullable', 'string', 'max:40']];
    }
}
