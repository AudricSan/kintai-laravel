<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Kintai\Contracts\Dashboard\DashboardWidget;

/**
 * Disposition d'un tableau de bord : widgets, ou reset.
 */
class UpdateDashboardLayoutRequest extends FormRequest
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
            'dashboard' => ['nullable', Rule::in([DashboardWidget::MANAGER, DashboardWidget::EMPLOYEE])],
            'reset' => ['nullable', 'boolean'],
            'name' => ['nullable', 'string', 'max:100'],
            'widgets' => ['required_without:reset', 'array'],
            'widgets.*' => ['array'],
            'widgets.*.widget' => ['required', 'string', 'max:100'],
            'widgets.*.size' => ['nullable', 'string', 'max:10'],
        ];
    }
}
