<?php

namespace App\Actions\Stores;

use App\Authorization\Authorizer;
use App\Events\StoreMemberRemoved;
use App\Models\Store;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Retire un employé d'un magasin : l'appartenance et ses rôles dans ce magasin disparaissent, ses shifts restent.
 */
class RemoveStoreMember
{
    use ManagesStoreMembers;

    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly StoreRoleManager $storeRoles,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, Store $store, User $target): void
    {
        $this->authorizeMembers($actor, $store);

        $store->members()->where('users.id', $target->id)->firstOrFail();

        if (! $this->authorizer->canActOnUser($actor, 'employees.update', $target)) {
            throw new AuthorizationException;
        }

        $this->storeRoles->assertNotSelf($actor, $target, 'member');

        DB::transaction(function () use ($store, $target): void {
            $store->members()->detach($target->id);
            $this->storeRoles->clear($target, $store);
        });

        StoreMemberRemoved::dispatch($store->id, $target->id);
    }
}
