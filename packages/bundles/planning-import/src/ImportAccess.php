<?php

namespace Kintai\Bundles\PlanningImport;

use App\Authorization\Authorizer;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Qui importe un planning et règle l'import : la permission `shifts.import` du Core, avec sa portée magasin. Le bundle
 * n'ajoute aucun droit.
 */
class ImportAccess
{
    public function __construct(private readonly Authorizer $authorizer) {}

    /**
     * Magasins actifs où l'utilisateur peut importer, nom par identifiant.
     *
     * @return array<int, string>
     */
    public function stores(User $user): array
    {
        $ids = $this->authorizer->storeIdsFor($user, 'shifts.import');
        if ($ids === []) {
            return [];
        }

        $query = Store::query()->where('is_active', true)->orderBy('name');
        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }

        $stores = [];
        foreach ($query->get(['id', 'name']) as $store) {
            $stores[$store->id] = $store->name;
        }

        return $stores;
    }

    public function canImport(User $user, int $storeId): bool
    {
        return isset($this->stores($user)[$storeId]);
    }

    /**
     * Employés actifs du magasin, nom par identifiant.
     *
     * @return array<int, string>
     */
    public function members(int $storeId): array
    {
        $members = [];
        $rows = User::query()
            ->whereIn('id', DB::table('store_user')->where('store_id', $storeId)->where('is_active', true)->select('user_id'))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        foreach ($rows as $user) {
            $members[$user->id] = $user->name;
        }

        return $members;
    }
}
