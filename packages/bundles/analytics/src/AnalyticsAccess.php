<?php

namespace Kintai\Bundles\Analytics;

use App\Authorization\Authorizer;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\User;

/**
 * Qui voit et exporte les statistiques : les droits `payroll.view` et `payroll.export` du Core, avec leur portée magasin.
 * Le bundle n'ajoute aucun droit.
 */
class AnalyticsAccess
{
    public const VIEW = 'payroll.view';

    public const EXPORT = 'payroll.export';

    public function __construct(private readonly Authorizer $authorizer) {}

    public function canView(User $user, int $storeId): bool
    {
        return $this->authorizer->allows($user, self::VIEW, $storeId);
    }

    public function canExport(User $user, int $storeId): bool
    {
        return $this->authorizer->allows($user, self::EXPORT, $storeId);
    }

    /**
     * Magasins actifs dont l'utilisateur voit les statistiques, triés par nom (identifiants).
     *
     * @return list<int>
     */
    public function viewableStoreIds(User $user): array
    {
        return $this->storeIds($user, self::VIEW);
    }

    /**
     * Magasins dont l'utilisateur voit les statistiques, nom par identifiant (ordre alphabétique).
     *
     * @return array<int, string>
     */
    public function viewableStores(User $user): array
    {
        $ids = $this->viewableStoreIds($user);
        if ($ids === []) {
            return [];
        }

        $names = Store::query()->whereIn('id', $ids)->pluck('name', 'id');
        $stores = [];
        foreach ($ids as $id) {
            $stores[$id] = (string) $names[$id];
        }

        return $stores;
    }

    /**
     * Magasins que l'utilisateur voit où l'employé est membre (identifiants).
     *
     * @return list<int>
     */
    public function storesOfEmployee(User $viewer, int $employeeId): array
    {
        $viewable = $this->viewableStoreIds($viewer);
        if ($viewable === []) {
            return [];
        }

        return StoreMembership::query()
            ->where('user_id', $employeeId)
            ->whereIn('store_id', $viewable)
            ->pluck('store_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    public function exportableStoreIds(User $user): array
    {
        return $this->storeIds($user, self::EXPORT);
    }

    /**
     * @return list<int>
     */
    private function storeIds(User $user, string $permission): array
    {
        $allowed = $this->authorizer->storeIdsFor($user, $permission);

        return Store::query()
            ->where('is_active', true)
            ->when($allowed !== null, fn ($query) => $query->whereIn('id', $allowed))
            ->orderBy('name')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
