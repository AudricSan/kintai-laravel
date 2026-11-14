<?php

namespace Kintai\Bundles\Timeclock\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pointage d'arrivée : magasin et heure du client facultatifs.
 */
class ClockInRequest extends FormRequest
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
        return ['store_id' => ['nullable', 'integer'], 'client_time' => ['nullable', 'string', 'max:40']];
    }
}
