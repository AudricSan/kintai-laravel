<?php

namespace App\Actions\Stores;

use App\Authorization\Authorizer;
use App\Models\Store;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Convention des actions sur les magasins : le droit manquant lève AuthorizationException (403) ;
 * une règle métier lève ValidationException, que le formulaire affiche.
 */
trait AuthorizesStoreActions
{
    /**
     * Droit sur un magasin précis.
     *
     * @throws AuthorizationException
     */
    protected function authorizeOnStore(User $actor, string $permission, Store $store): void
    {
        if (! app(Authorizer::class)->allows($actor, $permission, $store)) {
            throw new AuthorizationException;
        }
    }

    /**
     * Droit valable sur tous les magasins (Owner ou rôle « toutes les boutiques »).
     *
     * @throws AuthorizationException
     */
    protected function authorizeUnrestricted(User $actor, string $permission): void
    {
        if (! app(Authorizer::class)->hasUnrestricted($actor, $permission)) {
            throw new AuthorizationException;
        }
    }
}
