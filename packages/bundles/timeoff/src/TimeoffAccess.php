<?php

namespace Kintai\Bundles\Timeoff;

use App\Authorization\Authorizer;
use App\Models\Store;
use App\Models\StoreFeature;
use App\Models\StoreMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Kintai\Bundles\Timeoff\Models\TimeoffRequest;

/**
 * Qui voit, demande et décide quels congés. L'employé gère ses propres demandes sans permission (libre-service, comme
 * le profil), dans les magasins dont il est membre actif et où la fonctionnalité `timeoff` est activée. Les gérants
 * agissent sur les autres avec les permissions du bundle et la portée magasin du Core (`Authorizer`) :
 *
 * - `timeoff.view` : voir les demandes du magasin ;
 * - `timeoff.create` : saisir un congé pour un employé du magasin (approuvé d'emblée) ;
 * - `timeoff.update` : corriger une demande (API) ;
 * - `timeoff.approve` : approuver ou refuser ;
 * - `timeoff.delete` : supprimer, même un congé approuvé.
 */
class TimeoffAccess
{
    public function __construct(private readonly Authorizer $authorizer) {}

    /**
     * Magasins où l'employé peut déposer une demande : membre actif, magasin actif, fonctionnalité activée.
     *
     * @return array<int, string> identifiant => nom
     */
    public function memberStores(User $user): array
    {
        return $this->stores(StoreMembership::query()->where('user_id', $user->id)->where('is_active', true)->select('store_id'));
    }

    /**
     * Magasins actifs avec la fonctionnalité, parmi ceux de la requête.
     *
     * @param  Builder<StoreMembership>|null  $among
     * @return array<int, string>
     */
    private function stores(?Builder $among = null): array
    {
        $query = Store::query()
            ->where('is_active', true)
            ->whereNotIn('id', StoreFeature::query()->where('feature', TimeoffServiceProvider::FEATURE)->where('enabled', false)->select('store_id'))
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

    public function canView(User $user, TimeoffRequest $request): bool
    {
        return $request->user_id === $user->id || $this->authorizer->allows($user, 'timeoff.view', $request->store_id);
    }

    public function canApprove(User $user, TimeoffRequest $request): bool
    {
        return $this->authorizer->allows($user, 'timeoff.approve', $request->store_id);
    }

    public function canUpdate(User $user, TimeoffRequest $request): bool
    {
        return $this->authorizer->allows($user, 'timeoff.update', $request->store_id);
    }

    public function canDelete(User $user, TimeoffRequest $request): bool
    {
        return $this->authorizer->allows($user, 'timeoff.delete', $request->store_id);
    }

    public function canCancel(User $user, TimeoffRequest $request): bool
    {
        return $request->user_id === $user->id && $request->isPending();
    }

    /**
     * Demandes visibles : les siennes et celles des magasins où l'on a `timeoff.view`.
     *
     * @return Builder<TimeoffRequest>
     */
    public function visible(User $user): Builder
    {
        $ids = $this->authorizer->storeIdsFor($user, 'timeoff.view');

        return TimeoffRequest::query()->where(function (Builder $q) use ($user, $ids): void {
            $q->where('user_id', $user->id);

            if ($ids === null) {
                $q->orWhereRaw('1 = 1');
            } else {
                $q->orWhereIn('store_id', $ids);
            }
        });
    }

    /**
     * Demandes qu'un gérant peut voir pour les traiter (magasins où il a `timeoff.view`).
     *
     * @return Builder<TimeoffRequest>
     */
    public function managed(User $user): Builder
    {
        $ids = $this->authorizer->storeIdsFor($user, 'timeoff.view');

        return TimeoffRequest::query()->when($ids !== null, fn (Builder $q) => $q->whereIn('store_id', $ids ?? []));
    }

    /**
     * Demandes en attente qui concernent l'utilisateur : à décider s'il approuve quelque part, sinon les siennes.
     *
     * @return Builder<TimeoffRequest>
     */
    public function pendingFor(User $user): Builder
    {
        if (! $this->authorizer->allows($user, 'timeoff.approve')) {
            return TimeoffRequest::query()->where('user_id', $user->id)->where('status', TimeoffRequest::PENDING);
        }

        $ids = $this->authorizer->storeIdsFor($user, 'timeoff.approve');

        return TimeoffRequest::query()
            ->where('status', TimeoffRequest::PENDING)
            ->when($ids !== null, fn (Builder $q) => $q->whereIn('store_id', $ids ?? []));
    }
}
