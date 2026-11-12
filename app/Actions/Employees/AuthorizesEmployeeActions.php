<?php

namespace App\Actions\Employees;

use App\Authorization\Authorizer;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Convention des actions sur les employés : le droit manquant lève AuthorizationException (403) ;
 * une règle métier (Owner, soi-même…) lève ValidationException, que le formulaire affiche.
 */
trait AuthorizesEmployeeActions
{
    /**
     * @throws AuthorizationException
     */
    protected function authorizeOnEmployee(User $actor, string $permission, User $target): void
    {
        $authorizer = app(Authorizer::class);

        if (! $authorizer->allows($actor, $permission) || ! $authorizer->canActOnUser($actor, $permission, $target)) {
            throw new AuthorizationException;
        }
    }
}
