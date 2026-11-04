<?php

namespace App\Concerns;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Règles communes à la création et à l'édition d'un employé (formulaires Livewire, actions, futur import).
 */
trait EmployeeValidationRules
{
    /**
     * @return array<string, array<int, Unique|ValidationRule|string>>
     */
    protected function identityRules(?int $ignoreUserId = null): array
    {
        $unique = fn (): Unique => $ignoreUserId === null
            ? Rule::unique(User::class)
            : Rule::unique(User::class)->ignore($ignoreUserId);

        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'furigana_first_name' => ['required', 'string', 'max:255'],
            'furigana_last_name' => ['required', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'email', 'max:255', $unique()],
            'employee_code' => ['nullable', 'string', 'max:32', 'regex:/^[A-Z0-9_-]+$/', $unique()],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }

    /**
     * Coordonnées et données RH de l'employé.
     *
     * @return array<string, list<mixed>>
     */
    protected function hrRules(): array
    {
        return [
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'address' => ['nullable', 'string', 'max:500'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:50'],
            'mobile_phone' => ['nullable', 'string', 'max:50'],
            'tax_classification' => ['nullable', 'string', 'max:100'],
            'education' => ['nullable', 'string', 'max:255'],
            'guarantor_name' => ['nullable', 'string', 'max:255'],
            'guarantor_phone' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * Espaces retirés, chaînes vides remplacées par null, e-mail en minuscules, code employé en majuscules.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function normalizeEmployeeInput(array $input): array
    {
        foreach ($input as $key => $value) {
            if (is_string($value)) {
                $value = trim($value);
                $input[$key] = $value === '' ? null : $value;
            }
        }

        if (is_string($input['email'] ?? null)) {
            $input['email'] = Str::lower($input['email']);
        }

        if (is_string($input['employee_code'] ?? null)) {
            $input['employee_code'] = Str::upper($input['employee_code']);
        }

        return $input;
    }

    /**
     * @param  array<string, mixed>  $data  Données validées
     */
    protected function displayName(array $data): string
    {
        $name = $data['name'] ?? null;

        return is_string($name) && $name !== ''
            ? $name
            : trim($data['last_name'].' '.$data['first_name']);
    }
}
