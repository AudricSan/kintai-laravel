<?php

namespace App\Actions\Stores;

use App\Authorization\Authorizer;
use App\Models\Store;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\Rule;

/**
 * Droits et règles communs aux actions sur les membres d'un magasin.
 */
trait ManagesStoreMembers
{
    /**
     * Gérer les membres exige l'accès au magasin et `employees.update` sur ce magasin.
     *
     * @throws AuthorizationException
     */
    protected function authorizeMembers(User $actor, Store $store): void
    {
        $authorizer = app(Authorizer::class);

        if (! $authorizer->allows($actor, 'stores.view', $store) || ! $authorizer->allows($actor, 'employees.update', $store)) {
            throw new AuthorizationException;
        }
    }

    /**
     * Espaces retirés, chaînes vides remplacées par null.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function normalizeMemberInput(array $input): array
    {
        foreach ($input as $key => $value) {
            if (is_string($value)) {
                $value = trim($value);
                $input[$key] = $value === '' ? null : $value;
            }
        }

        return $input;
    }

    /**
     * @return array<string, mixed>
     */
    protected function memberRules(Store $store, ?int $ignoreMembershipId = null): array
    {
        $unique = Rule::unique('store_user', 'staff_code')->where('store_id', $store->id);
        if ($ignoreMembershipId !== null) {
            $unique->ignore($ignoreMembershipId);
        }

        return [
            'staff_code' => ['nullable', 'string', 'max:50', $unique],
            'hire_date' => ['nullable', 'date'],
            'contract_type' => ['nullable', 'string', 'max:50'],
        ];
    }
}
