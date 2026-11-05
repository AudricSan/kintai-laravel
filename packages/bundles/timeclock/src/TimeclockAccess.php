<?php

namespace Kintai\Bundles\Timeclock;

use App\Authorization\Authorizer;
use App\Models\Store;
use App\Models\StoreFeature;
use App\Models\StoreMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Kintai\Bundles\Timeclock\Models\Timeclock;
use Kintai\Contracts\Settings\Settings;

/**
 * Qui pointe et qui voit les pointages. L'employé pointe et consulte les siens sans permission (libre-service), dans les
 * magasins dont il est membre actif et où la fonctionnalité `timeclock` est activée. Les gérants agissent sur les autres
 * avec les permissions du bundle et la portée magasin du Core : `timeclock.view` (voir), `timeclock.update` (corriger),
 * `timeclock.delete` (supprimer).
 */
class TimeclockAccess
{
    public function __construct(private readonly Authorizer $authorizer, private readonly Settings $settings) {}

    /**
     * Magasins où l'employé peut pointer : membre actif, magasin actif, fonctionnalité activée.
     *
     * @return array<int, string> identifiant => nom
     */
    public function memberStores(User $user): array
    {
        return $this->stores(StoreMembership::query()->where('user_id', $user->id)->where('is_active', true)->select('store_id'));
    }

    /**
     * Magasins où l'utilisateur a la permission, avec la fonctionnalité activée.
     *
     * @return array<int, string>
     */
    public function storesWith(User $user, string $permission): array
    {
        $ids = $this->authorizer->storeIdsFor($user, $permission);
        $stores = $this->stores();

        return $ids === null ? $stores : array_intersect_key($stores, array_flip($ids));
    }

    /**
     * @param  Builder<StoreMembership>|null  $among
     * @return array<int, string>
     */
    private function stores(?Builder $among = null): array
    {
        $query = Store::query()
            ->where('is_active', true)
            ->whereNotIn('id', StoreFeature::query()->where('feature', TimeclockServiceProvider::FEATURE)->where('enabled', false)->select('store_id'))
            ->orderBy('name');

        if ($among !== null) {
            $query->whereIn('id', $among);
        }

        $stores = [];
        foreach ($query->get(['id', 'name']) as $store) {
            $stores[$store->id] = $store->name;
        }

        return $stores;
    }

    public function canView(User $user, Timeclock $entry): bool
    {
        return $entry->user_id === $user->id || $this->authorizer->allows($user, 'timeclock.view', $entry->store_id);
    }

    public function canUpdate(User $user, Timeclock $entry): bool
    {
        return $this->authorizer->allows($user, 'timeclock.update', $entry->store_id);
    }

    public function canDelete(User $user, Timeclock $entry): bool
    {
        return $this->authorizer->allows($user, 'timeclock.delete', $entry->store_id);
    }

    /**
     * Pointages visibles : les siens et ceux des magasins où l'on a `timeclock.view`.
     *
     * @return Builder<Timeclock>
     */
    public function visible(User $user): Builder
    {
        $ids = $this->authorizer->storeIdsFor($user, 'timeclock.view');

        return Timeclock::query()->where(function (Builder $q) use ($user, $ids): void {
            $q->where('user_id', $user->id);

            if ($ids === null) {
                $q->orWhereRaw('1 = 1');
            } else {
                $q->orWhereIn('store_id', $ids);
            }
        });
    }

    /**
     * Pointages des magasins où l'on a `timeclock.view`.
     *
     * @return Builder<Timeclock>
     */
    public function managed(User $user): Builder
    {
        $ids = $this->authorizer->storeIdsFor($user, 'timeclock.view');

        return Timeclock::query()->when($ids !== null, fn (Builder $q) => $q->whereIn('store_id', $ids ?? []));
    }

    /**
     * Seuil de retard en minutes (réglage `timeclock.late_threshold_minutes`).
     */
    public function lateThreshold(): int
    {
        return $this->settings->int('timeclock.late_threshold_minutes');
    }
}
