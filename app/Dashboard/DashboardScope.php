<?php

namespace App\Dashboard;

use App\Authorization\Authorizer;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Magasins visibles d'un utilisateur sur ses tableaux de bord : un gérant restreint ne voit que les siens.
 */
class DashboardScope
{
    public function __construct(private readonly Authorizer $authorizer) {}

    /**
     * Aujourd'hui dans le fuseau de l'instance : les shifts sont rattachés à un jour local.
     */
    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('kintai.timezone', 'UTC'))->startOfDay();
    }

    /**
     * Magasins actifs sur lesquels l'utilisateur détient la permission (tous s'il n'est pas restreint).
     *
     * @return Collection<int, Store>
     */
    public function managedStores(User $user, string $permission = 'shifts.view'): Collection
    {
        $ids = $this->authorizer->storeIdsFor($user, $permission);

        return Store::query()
            ->where('is_active', true)
            ->when($ids !== null, fn ($query) => $query->whereIn('id', $ids))
            ->orderBy('name')
            ->get();
    }

    /**
     * @return list<int>
     */
    public function managedStoreIds(User $user, string $permission = 'shifts.view'): array
    {
        return $this->managedStores($user, $permission)->modelKeys();
    }

    /**
     * Magasins actifs dont l'utilisateur est membre actif.
     *
     * @return Collection<int, Store>
     */
    public function memberStores(User $user): Collection
    {
        return $user->stores()
            ->where('stores.is_active', true)
            ->wherePivot('is_active', true)
            ->orderBy('stores.name')
            ->get();
    }

    /**
     * Magasins pris en compte pour la fonctionnalité d'un widget.
     *
     * @return Collection<int, Store>
     */
    public function storesFor(User $user, DashboardWidget $widget): Collection
    {
        return $widget->audience === DashboardWidget::MANAGER
            ? $this->managedStores($user, $widget->permission ?? 'shifts.view')
            : $this->memberStores($user);
    }
}
