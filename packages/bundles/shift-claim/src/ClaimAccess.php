<?php

namespace Kintai\Bundles\ShiftClaim;

use App\Authorization\Authorizer;
use App\Models\Shift;
use App\Models\Store;
use App\Models\StoreFeature;
use App\Models\StoreMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Kintai\Bundles\ShiftClaim\Models\ShiftClaim;
use Kintai\Bundles\ShiftClaim\Models\ShiftOffer;

/**
 * Qui voit la bourse, y candidate et décide. Un employé candidate sans permission (libre-service) aux shifts des magasins
 * dont il est membre actif et où la fonctionnalité `open_shifts` est activée. Les gérants agissent avec les permissions
 * du bundle et la portée magasin du Core (`Authorizer`) :
 *
 * - `open_shifts.view` : voir la bourse et les candidatures du magasin ;
 * - `open_shifts.publish` : proposer un shift attribué à la bourse, ou le retirer ;
 * - `open_shifts.approve` : approuver ou rejeter une candidature (l'approbation écrit aussi dans le planning :
 *   `shifts.update` et `shifts.create` du magasin, vérifiés par le Core).
 *
 * Un shift est dans la bourse tant qu'il est actif, à venir, et soit ouvert (sans employé), soit proposé par un gérant.
 */
class ClaimAccess
{
    public function __construct(private readonly Authorizer $authorizer) {}

    /**
     * Magasins où l'employé peut candidater : membre actif, magasin actif, fonctionnalité activée.
     *
     * @return array<int, string> identifiant => nom
     */
    public function memberStores(User $user): array
    {
        return $this->stores(StoreMembership::query()->where('user_id', $user->id)->where('is_active', true)->select('store_id'));
    }

    /**
     * @param  Builder<StoreMembership>|null  $among
     * @return array<int, string>
     */
    private function stores(?Builder $among = null): array
    {
        $query = Store::query()
            ->where('is_active', true)
            ->whereNotIn('id', StoreFeature::query()->where('feature', ShiftClaimServiceProvider::FEATURE)->where('enabled', false)->select('store_id'))
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
     * La fonctionnalité `open_shifts` est-elle activée sur le magasin ?
     */
    public function featureEnabled(int $storeId): bool
    {
        return StoreFeature::query()->where('store_id', $storeId)->where('feature', ShiftClaimServiceProvider::FEATURE)->where('enabled', false)->doesntExist();
    }

    /**
     * La bourse d'un ensemble de magasins : shifts actifs à venir, ouverts ou proposés.
     *
     * @param  list<int>  $storeIds
     * @return Builder<Shift>
     */
    public function pool(array $storeIds): Builder
    {
        return Shift::query()
            ->whereIn('store_id', $storeIds)
            ->whereNull('replaced_by_id')
            ->where('starts_at', '>', Carbon::now())
            ->where(fn (Builder $q) => $q->whereNull('user_id')->orWhereIn('id', ShiftOffer::query()->select('shift_id')))
            ->orderBy('starts_at')
            ->orderBy('id');
    }

    /**
     * Le shift est-il dans la bourse (à la lecture : sans verrou) ?
     */
    public function isClaimable(Shift $shift): bool
    {
        if ($shift->trashed() || $shift->replaced_by_id !== null || $shift->starts_at->lessThanOrEqualTo(Carbon::now())) {
            return false;
        }

        return $shift->user_id === null || ShiftOffer::query()->where('shift_id', $shift->id)->exists();
    }

    public function canView(User $user, ShiftClaim $claim): bool
    {
        return $claim->user_id === $user->id || $this->authorizer->allows($user, 'open_shifts.view', $claim->store_id);
    }

    public function canApprove(User $user, ShiftClaim $claim): bool
    {
        return $this->authorizer->allows($user, 'open_shifts.approve', $claim->store_id);
    }

    public function canPublish(User $user, int $storeId): bool
    {
        return $this->authorizer->allows($user, 'open_shifts.publish', $storeId);
    }

    public function canWithdraw(User $user, ShiftClaim $claim): bool
    {
        return $claim->user_id === $user->id && $claim->isPending();
    }

    /**
     * Candidatures visibles : les siennes et celles des magasins où l'on a `open_shifts.view`.
     *
     * @return Builder<ShiftClaim>
     */
    public function visible(User $user): Builder
    {
        $ids = $this->authorizer->storeIdsFor($user, 'open_shifts.view');

        return ShiftClaim::query()->where(function (Builder $q) use ($user, $ids): void {
            $q->where('user_id', $user->id);

            if ($ids === null) {
                $q->orWhereRaw('1 = 1');
            } else {
                $q->orWhereIn('store_id', $ids);
            }
        });
    }

    /**
     * Candidatures en attente qui concernent l'utilisateur : à décider s'il approuve quelque part, sinon les siennes.
     *
     * @return Builder<ShiftClaim>
     */
    public function pendingFor(User $user): Builder
    {
        if (! $this->authorizer->allows($user, 'open_shifts.approve')) {
            return ShiftClaim::query()->where('user_id', $user->id)->where('status', ShiftClaim::PENDING);
        }

        $ids = $this->authorizer->storeIdsFor($user, 'open_shifts.approve');

        return ShiftClaim::query()
            ->where('status', ShiftClaim::PENDING)
            ->when($ids !== null, fn (Builder $q) => $q->whereIn('store_id', $ids ?? []));
    }
}
