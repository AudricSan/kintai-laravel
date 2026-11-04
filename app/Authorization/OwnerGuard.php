<?php

namespace App\Authorization;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Protège les comptes Owner. « Retirer » = supprimer ou anonymiser, désactiver, retirer le rôle Owner.
 */
class OwnerGuard
{
    public function __construct(private readonly Authorizer $authorizer) {}

    /**
     * @throws AuthorizationException
     */
    public function assertCanRemoveOwner(User $actor, User $target): void
    {
        if (! $this->authorizer->isOwner($target)) {
            return;
        }

        if ($actor->id === $target->id) {
            throw new AuthorizationException(__('You cannot remove your own Owner account.'));
        }

        $remaining = array_diff($this->authorizer->activeOwnerIds(), [$target->id]);

        if ($remaining === []) {
            throw new AuthorizationException(__('The last active Owner cannot be removed.'));
        }
    }

    /**
     * @throws AuthorizationException
     */
    public function assertNotSelfUnlessOwner(User $actor, User $target): void
    {
        if ($actor->id === $target->id && ! $this->authorizer->isOwner($actor)) {
            throw new AuthorizationException(__('You cannot do this on your own account.'));
        }
    }
}
