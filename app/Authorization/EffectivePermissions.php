<?php

namespace App\Authorization;

use App\Models\RoleAssignment;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Collection;
use Kintai\Contracts\Authorization\PermissionRegistry;

/**
 * Droits effectifs d'un utilisateur, calculés avec l'`Authorizer` (la seule source de vérité) : ceux valables sur tous
 * les magasins, et ceux valables magasin par magasin. Sert aux clients (application mobile) pour afficher ou masquer
 * des actions ; le serveur reste seul juge à chaque requête.
 */
class EffectivePermissions
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly PermissionRegistry $registry,
    ) {}

    /**
     * Permissions valables sur tous les magasins.
     *
     * @return list<string>
     */
    public function global(User $user): array
    {
        return array_values(array_filter(
            $this->registry->all(),
            fn (string $permission): bool => $this->authorizer->hasUnrestricted($user, $permission),
        ));
    }

    /**
     * Magasins de l'utilisateur (appartenance ou rôle attribué sur le magasin), avec les permissions effectives sur chacun.
     *
     * @return Collection<int, array{store: Store, permissions: list<string>}>
     */
    public function byStore(User $user): Collection
    {
        $ids = $user->stores()->pluck('stores.id')
            ->merge(RoleAssignment::query()->where('user_id', $user->id)->whereNotNull('store_id')->pluck('store_id'))
            ->unique()
            ->values();

        return Store::query()->whereIn('id', $ids)->orderBy('name')->get()->map(fn (Store $store): array => [
            'store' => $store,
            'permissions' => array_values(array_filter(
                $this->registry->all(),
                fn (string $permission): bool => $this->authorizer->allows($user, $permission, $store),
            )),
        ]);
    }
}
