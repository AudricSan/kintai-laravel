<?php

namespace App\Actions\Roles;

use App\Authorization\OwnerGuard;
use App\Events\RoleHoldersChanged;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Store;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Titulaires d'un rôle : attribution et retrait (réservés à l'Owner).
 */
class ManageRoleHolders
{
    public function __construct(private readonly OwnerGuard $ownerGuard) {}

    /**
     * @param  int|null  $storeId  null = global (seule portée possible pour le rôle système)
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function add(User $actor, Role $role, int $userId, ?int $storeId): void
    {
        $this->authorize($actor);

        $user = User::query()->where('is_active', true)->find($userId);

        if ($user === null) {
            throw ValidationException::withMessages(['holder_user' => __('Select an active user.')]);
        }

        $store = null;
        if ($storeId !== null) {
            if ($role->is_system) {
                throw ValidationException::withMessages(['holder_store' => __('The Owner role can only be assigned globally.')]);
            }

            $store = Store::query()->find($storeId);

            if ($store === null || ! DB::table('store_user')->where('user_id', $user->id)->where('store_id', $store->id)->exists()) {
                throw ValidationException::withMessages(['holder_store' => __('The user is not a member of this store.')]);
            }

            $hasRoleHere = RoleAssignment::query()->where('user_id', $user->id)->where('store_id', $store->id)->exists();
            if ($hasRoleHere) {
                throw ValidationException::withMessages(['holder_user' => __('This user already has a role in this store.')]);
            }
        }

        $user->assignRole($role, $store);

        RoleHoldersChanged::dispatch($role->id, $user->id, $store?->id, true);
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function remove(User $actor, Role $role, int $assignmentId): void
    {
        $this->authorize($actor);

        $assignment = RoleAssignment::query()->where('role_id', $role->id)->findOrFail($assignmentId);
        $user = User::withTrashed()->findOrFail($assignment->user_id);

        if ($role->is_system) {
            try {
                $this->ownerGuard->assertCanRemoveOwner($actor, $user);
            } catch (AuthorizationException $e) {
                throw ValidationException::withMessages(['holder' => $e->getMessage()]);
            }
        }

        $user->removeRole($role, $assignment->store_id === null ? null : Store::query()->findOrFail($assignment->store_id));

        RoleHoldersChanged::dispatch($role->id, $user->id, $assignment->store_id, false);
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(User $actor): void
    {
        if (! Gate::forUser($actor)->allows('manage-roles')) {
            throw new AuthorizationException;
        }
    }
}
