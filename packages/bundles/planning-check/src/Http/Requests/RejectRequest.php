<?php

namespace Kintai\Bundles\PlanningCheck\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Rejet d'une vérification, avec son motif.
 */
class RejectRequest extends FormRequest
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
        return ['reason' => ['required', 'string', 'max:500']];
    }
}
