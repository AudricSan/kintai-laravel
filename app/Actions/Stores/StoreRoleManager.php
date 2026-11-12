<?php

namespace App\Actions\Stores;

use App\Authorization\Authorizer;
use App\Authorization\OwnerGuard;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Store;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Règles du rôle d'un employé dans un magasin, partagées entre la fiche employé et les membres d'un magasin.
 */
class StoreRoleManager
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly OwnerGuard $ownerGuard,
    ) {}

    /**
     * Rôle à poser, ou null s'il est déjà le seul rôle de l'employé dans ce magasin (le plafond n'est alors pas redemandé).
     *
     * @throws ValidationException
     */
    public function resolveChange(User $actor, User $target, Store $store, mixed $roleId, string $field): ?Role
    {
        $role = is_numeric($roleId) ? Role::query()->find($roleId) : null;

        if ($role === null || $role->is_system) {
            throw ValidationException::withMessages([$field => __('You cannot assign this role.')]);
        }

        $current = RoleAssignment::query()
            ->where('user_id', $target->id)
            ->where('store_id', $store->id)
            ->pluck('role_id')
            ->all();

        if ($current === [$role->id]) {
            return null;
        }

        if (! $this->authorizer->canAssignRole($actor, $role, $store)) {
            throw ValidationException::withMessages([$field => __('You cannot assign this role.')]);
        }

        return $role;
    }

    /**
     * Un non-Owner ne modifie pas son propre compte (rôle, désactivation) : il perdrait des droits sans retour possible.
     *
     * @throws ValidationException
     */
    public function assertNotSelf(User $actor, User $target, string $field): void
    {
        try {
            $this->ownerGuard->assertNotSelfUnlessOwner($actor, $target);
        } catch (AuthorizationException $e) {
            throw ValidationException::withMessages([$field => $e->getMessage()]);
        }
    }

    /**
     * Remplace les rôles de l'employé dans ce magasin par celui-ci.
     */
    public function apply(User $target, Store $store, Role $role): void
    {
        $this->clear($target, $store);
        $target->assignRole($role, $store);
    }

    /**
     * Supprime les attributions de rôle de l'employé dans ce magasin (celles des autres magasins restent).
     */
    public function clear(User $target, Store $store): void
    {
        RoleAssignment::query()->where('user_id', $target->id)->where('store_id', $store->id)->delete();
        $this->authorizer->forget($target);
    }
}
