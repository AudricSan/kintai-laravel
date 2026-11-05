<?php

namespace App\Stores;

use App\Authorization\Authorizer;
use App\Models\EmployeeProfile;
use App\Models\Store;
use App\Models\StoreFeature;
use App\Models\StoreMembership;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Kintai\Contracts\Stores\Colleague;
use Kintai\Contracts\Stores\ColleagueDirectory;

/**
 * Les collègues d'un utilisateur et ce qu'il peut en voir (voir l'interface du contrat pour les règles).
 *
 * Coordonnées d'un collègue : e-mail si `share_email`, téléphone si `share_phone`, mobile si `share_mobile_phone`
 * (téléphones lus dans la fiche RH, qui ne sort jamais d'autre façon) ; toutes, quel que soit le choix du collègue, pour
 * qui détient `employees.view` sur un magasin commun (gérant, Owner).
 */
class StoreColleagueDirectory implements ColleagueDirectory
{
    public function __construct(private readonly Authorizer $authorizer) {}

    public function stores(Authenticatable $viewer, ?string $feature = null): array
    {
        if (! $viewer instanceof User) {
            return [];
        }

        $query = Store::query()->orderBy('name')->orderBy('id');

        if (! $this->authorizer->isOwner($viewer)) {
            $query->whereIn('id', StoreMembership::query()
                ->where('user_id', $viewer->id)
                ->where('is_active', true)
                ->select('store_id'));
        }

        if ($feature !== null) {
            // Sans ligne, la fonctionnalité est active.
            $query->whereNotIn('id', StoreFeature::query()
                ->where('feature', $feature)
                ->where('enabled', false)
                ->select('store_id'));
        }

        $stores = [];
        foreach ($query->get(['id', 'name']) as $store) {
            $stores[$store->id] = $store->name;
        }

        return $stores;
    }

    public function colleagues(Authenticatable $viewer, array $storeIds): array
    {
        return $this->build($viewer, $storeIds, null);
    }

    public function colleague(Authenticatable $viewer, int $userId, array $storeIds): ?Colleague
    {
        return $this->build($viewer, $storeIds, $userId)[0] ?? null;
    }

    /**
     * @param  list<int>  $storeIds
     * @return list<Colleague>
     */
    private function build(Authenticatable $viewer, array $storeIds, ?int $onlyUserId): array
    {
        if (! $viewer instanceof User) {
            return [];
        }

        $stores = $this->stores($viewer);
        $allowed = array_intersect_key($stores, array_flip($storeIds));

        $isSelfRequest = $onlyUserId === $viewer->id;

        if ($allowed === [] && ! $isSelfRequest) {
            return [];
        }

        $query = StoreMembership::query()
            ->whereIn('store_id', array_keys($allowed))
            ->where('is_active', true);

        if ($onlyUserId !== null) {
            $query->where('user_id', $onlyUserId);
        } else {
            $query->where('user_id', '!=', $viewer->id);
        }

        /** @var array<int, array<int, string>> $sharedStores user_id => [store_id => name] */
        $sharedStores = [];
        foreach ($query->get(['store_id', 'user_id']) as $membership) {
            $sharedStores[$membership->user_id][$membership->store_id] = $allowed[$membership->store_id];
        }

        if ($isSelfRequest) {
            // Soi-même : toujours visible, avec ses propres magasins.
            $sharedStores[$viewer->id] ??= [];
        }

        if ($sharedStores === []) {
            return [];
        }

        // Les comptes inactifs et anonymisés (supprimés) n'ont pas leur place dans l'annuaire.
        $users = User::query()
            ->whereIn('id', array_keys($sharedStores))
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $phones = EmployeeProfile::query()
            ->whereIn('user_id', $users->keys())
            ->get(['user_id', 'phone', 'mobile_phone'])
            ->keyBy('user_id');

        $colleagues = [];
        foreach ($users as $user) {
            $shared = $sharedStores[$user->id];
            $self = $user->id === $viewer->id;
            $seesAll = $self || $this->managesAny($viewer, array_keys($shared));
            $profile = $phones->get($user->id);

            $colleagues[] = new Colleague(
                id: $user->id,
                name: $user->name,
                firstName: $user->first_name,
                lastName: $user->last_name,
                color: $user->color,
                avatarUrl: $user->avatarUrl(),
                stores: $shared,
                email: ($seesAll || $user->share_email) ? $this->filled($user->email) : null,
                phone: ($seesAll || $user->share_phone) ? $this->filled($profile?->phone) : null,
                mobilePhone: ($seesAll || $user->share_mobile_phone) ? $this->filled($profile?->mobile_phone) : null,
                isSelf: $self,
            );
        }

        usort($colleagues, fn (Colleague $a, Colleague $b): int => [mb_strtolower($a->name), $a->id] <=> [mb_strtolower($b->name), $b->id]);

        return $colleagues;
    }

    /**
     * @param  list<int>  $storeIds
     */
    private function managesAny(User $viewer, array $storeIds): bool
    {
        foreach ($storeIds as $storeId) {
            if ($this->authorizer->allows($viewer, 'employees.view', $storeId)) {
                return true;
            }
        }

        return false;
    }

    private function filled(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : $value;
    }
}
