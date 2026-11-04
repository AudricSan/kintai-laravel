<?php

namespace App\Actions\Ical;

use App\Authorization\Authorizer;
use App\Models\Store;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Qui peut gérer le lien iCal d'un employé : lui-même, pour un magasin dont il est membre ; un gérant qui a
 * `employees.update` sur ce magasin, pour un employé de ce magasin.
 */
trait AuthorizesIcalTokens
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    protected function authorizeIcalToken(User $actor, User $target, Store $store): void
    {
        $authorizer = app(Authorizer::class);

        if ($actor->id !== $target->id
            && (! $authorizer->allows($actor, 'employees.update', $store) || ! $authorizer->canActOnUser($actor, 'employees.update', $target))) {
            throw new AuthorizationException;
        }

        if (! $target->stores()->where('stores.id', $store->id)->exists()) {
            throw ValidationException::withMessages(['store' => __('This employee does not belong to this store.')]);
        }
    }
}
