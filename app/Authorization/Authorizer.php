<?php

namespace App\Authorization;

use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Évalue les droits d'un utilisateur à partir de ses rôles (globaux ou par magasin).
 * Les attributions sont chargées en une requête et gardées par utilisateur pour la durée de vie du conteneur.
 *
 * @phpstan-type Assignment array{store_id: int|null, is_system: bool, is_manager: bool, permissions: array<string, bool>}
 */
class Authorizer
{
    /** @var array<int, list<Assignment>> */
    private array $cache = [];

    public function forget(?User $user = null): void
    {
        if ($user === null) {
            $this->cache = [];

            return;
        }

        unset($this->cache[$user->id]);
    }

    public function isOwner(User $user): bool
    {
        foreach ($this->assignments($user) as $assignment) {
            if ($assignment['store_id'] === null && $assignment['is_system']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sert à la navigation, indépendamment des permissions.
     */
    public function isManager(User $user): bool
    {
        if ($this->isOwner($user)) {
            return true;
        }

        foreach ($this->assignments($user) as $assignment) {
            if ($assignment['is_manager']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sans magasin : « quelque part », c'est-à-dire sur au moins un magasin.
     */
    public function allows(User $user, string $permission, Store|int|null $store = null): bool
    {
        if ($this->isOwner($user)) {
            return true;
        }

        $storeId = $store instanceof Store ? $store->id : $store;

        foreach ($this->assignments($user) as $assignment) {
            if (! array_key_exists($permission, $assignment['permissions'])) {
                continue;
            }

            if ($storeId === null
                || $assignment['store_id'] === null
                || $assignment['permissions'][$permission]
                || $assignment['store_id'] === $storeId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Permission valable sur tous les magasins.
     */
    public function hasUnrestricted(User $user, string $permission): bool
    {
        if ($this->isOwner($user)) {
            return true;
        }

        foreach ($this->assignments($user) as $assignment) {
            if (array_key_exists($permission, $assignment['permissions'])
                && ($assignment['store_id'] === null || $assignment['permissions'][$permission])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Magasins sur lesquels la permission est accordée ; null = tous.
     *
     * @return list<int>|null
     */
    public function storeIdsFor(User $user, string $permission): ?array
    {
        if ($this->hasUnrestricted($user, $permission)) {
            return null;
        }

        $ids = [];
        foreach ($this->assignments($user) as $assignment) {
            if ($assignment['store_id'] !== null && array_key_exists($permission, $assignment['permissions'])) {
                $ids[$assignment['store_id']] = $assignment['store_id'];
            }
        }

        return array_values($ids);
    }

    /**
     * Plafond anti-escalade : l'acteur ne peut attribuer que des droits qu'il détient lui-même.
     */
    public function canAssignRole(User $actor, Role $role, ?Store $store): bool
    {
        if ($role->is_system) {
            return $store === null && $this->isOwner($actor);
        }

        foreach ($role->permissions()->get() as $rolePermission) {
            $permission = $rolePermission->permission;

            $held = $store === null
                ? $this->hasUnrestricted($actor, $permission)
                : $this->allows($actor, $permission, $store);

            if (! $held) {
                return false;
            }

            if ($rolePermission->all_stores && ! $this->hasUnrestricted($actor, $permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Agir sur les identifiants d'un compte (mot de passe, e-mail) revient à en prendre les droits : en plus de
     * `canActOnUser`, tous les droits du compte visé doivent être inclus dans ceux de l'acteur (même logique que
     * `canAssignRole`). Soi-même : toujours permis.
     */
    public function canTakeOverAccount(User $actor, string $permission, User $target): bool
    {
        if ($actor->id === $target->id) {
            return true;
        }

        return $this->canActOnUser($actor, $permission, $target)
            && ($this->isOwner($actor) || $this->coversTargetRights($actor, $target));
    }

    public function canActOnUser(User $actor, string $permission, User $target): bool
    {
        if ($actor->id === $target->id) {
            return true;
        }

        if ($this->isOwner($target) && ! $this->isOwner($actor)) {
            return false;
        }

        $allowedStoreIds = $this->storeIdsFor($actor, $permission);

        if ($allowedStoreIds === null) {
            return true;
        }

        $targetStoreIds = StoreMembership::query()
            ->where('user_id', $target->id)
            ->pluck('store_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return array_intersect($allowedStoreIds, $targetStoreIds) !== [];
    }

    /**
     * Plafond anti-prise de compte : agir sur un compte (mot de passe, e-mail) revient à en prendre les droits ; l'acteur
     * ne peut donc agir que sur un compte dont tous les droits sont inclus dans les siens (même logique que
     * `canAssignRole`).
     */
    private function coversTargetRights(User $actor, User $target): bool
    {
        foreach ($this->assignments($target) as $assignment) {
            foreach ($assignment['permissions'] as $permission => $allStores) {
                $held = $assignment['store_id'] === null || $allStores
                    ? $this->hasUnrestricted($actor, $permission)
                    : $this->allows($actor, $permission, $assignment['store_id']);

                if (! $held) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return list<int>
     */
    public function ownerIds(): array
    {
        return $this->ids($this->ownerAssignments());
    }

    /**
     * Owners dont le compte est actif et non supprimé.
     *
     * @return list<int>
     */
    public function activeOwnerIds(): array
    {
        // `whereHas('user')` applique la suppression logique du modèle User : un compte supprimé n'est jamais actif.
        return $this->ids($this->ownerAssignments()->whereHas('user', fn (Builder $user) => $user->where('is_active', true)));
    }

    /**
     * Attributions globales d'un rôle système (Owner).
     *
     * @return Builder<RoleAssignment>
     */
    private function ownerAssignments(): Builder
    {
        return RoleAssignment::query()
            ->whereNull('store_id')
            ->whereHas('role', fn (Builder $role) => $role->where('is_system', true));
    }

    /**
     * @param  Builder<RoleAssignment>  $query
     * @return list<int>
     */
    private function ids(Builder $query): array
    {
        return $query->distinct()
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return list<Assignment>
     */
    private function assignments(User $user): array
    {
        if (isset($this->cache[$user->id])) {
            return $this->cache[$user->id];
        }

        // Requête brute volontaire : une seule jointure (attributions, rôles, permissions) mise en cache par utilisateur,
        // sur le chemin de chaque vérification de droit. Hydrater des modèles et leurs relations coûterait plus de requêtes.
        $rows = DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->leftJoin('role_permissions', 'role_permissions.role_id', '=', 'roles.id')
            ->where('role_user.user_id', $user->id)
            ->get([
                'role_user.id as assignment_id',
                'role_user.store_id',
                'roles.is_system',
                'roles.is_manager',
                'role_permissions.permission',
                'role_permissions.all_stores',
            ]);

        $assignments = [];
        foreach ($rows as $row) {
            $id = (int) $row->assignment_id;
            $assignments[$id] ??= [
                'store_id' => $row->store_id === null ? null : (int) $row->store_id,
                'is_system' => (bool) $row->is_system,
                'is_manager' => (bool) $row->is_manager,
                'permissions' => [],
            ];

            if ($row->permission !== null) {
                $assignments[$id]['permissions'][(string) $row->permission] = (bool) $row->all_stores;
            }
        }

        return $this->cache[$user->id] = array_values($assignments);
    }
}
