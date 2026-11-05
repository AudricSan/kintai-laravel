<?php

namespace Kintai\Bundles\ShiftSwap;

use App\Authorization\Authorizer;
use App\Models\Shift;
use App\Models\Store;
use App\Models\StoreFeature;
use App\Models\StoreMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Kintai\Bundles\ShiftSwap\Models\ShiftSwapRequest;

/**
 * Qui voit, propose et décide quels échanges. Un employé propose et répond sans permission (libre-service) dans les
 * magasins dont il est membre actif et où la fonctionnalité `swaps` est activée. Les gérants agissent avec les
 * permissions du bundle et la portée magasin du Core (`Authorizer`) :
 *
 * - `swaps.view` : voir les demandes du magasin ;
 * - `swaps.create` : appliquer d'emblée un échange entre deux employés ;
 * - `swaps.update` : corriger le motif d'une demande en attente (API) ;
 * - `swaps.approve` : approuver ou refuser (l'approbation écrit aussi dans le planning : `shifts.update` et
 *   `shifts.create` du magasin, vérifiés par le Core) ;
 * - `swaps.delete` : supprimer, en rétablissant les shifts d'un échange appliqué.
 */
class SwapAccess
{
    public function __construct(private readonly Authorizer $authorizer) {}

    /**
     * Magasins où l'employé peut échanger : membre actif, magasin actif, fonctionnalité activée.
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
            ->whereNotIn('id', StoreFeature::query()->where('feature', ShiftSwapServiceProvider::FEATURE)->where('enabled', false)->select('store_id'))
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

    public function canView(User $user, ShiftSwapRequest $swap): bool
    {
        return $this->involves($user, $swap) || $this->authorizer->allows($user, 'swaps.view', $swap->store_id);
    }

    public function involves(User $user, ShiftSwapRequest $swap): bool
    {
        return $swap->requester_id === $user->id || $swap->target_user_id === $user->id;
    }

    public function canApprove(User $user, ShiftSwapRequest $swap): bool
    {
        return $this->authorizer->allows($user, 'swaps.approve', $swap->store_id);
    }

    public function canUpdate(User $user, ShiftSwapRequest $swap): bool
    {
        return $this->authorizer->allows($user, 'swaps.update', $swap->store_id);
    }

    public function canDelete(User $user, ShiftSwapRequest $swap): bool
    {
        return $this->authorizer->allows($user, 'swaps.delete', $swap->store_id);
    }

    public function canCreate(User $user, int $storeId): bool
    {
        return $this->authorizer->allows($user, 'swaps.create', $storeId);
    }

    /** Le collègue peut accepter ou refuser. */
    public function canAnswer(User $user, ShiftSwapRequest $swap): bool
    {
        return $swap->target_user_id === $user->id && $swap->awaitsColleague();
    }

    /** Le demandeur peut retirer sa demande tant qu'elle n'est pas décidée. */
    public function canCancel(User $user, ShiftSwapRequest $swap): bool
    {
        return $swap->requester_id === $user->id && $swap->isPending();
    }

    /**
     * Demandes visibles : les siennes (demandeur ou collègue) et celles des magasins où l'on a `swaps.view`.
     *
     * @return Builder<ShiftSwapRequest>
     */
    public function visible(User $user): Builder
    {
        $ids = $this->authorizer->storeIdsFor($user, 'swaps.view');

        return ShiftSwapRequest::query()->where(function (Builder $q) use ($user, $ids): void {
            $q->where('requester_id', $user->id)->orWhere('target_user_id', $user->id);

            if ($ids === null) {
                $q->orWhereRaw('1 = 1');
            } else {
                $q->orWhereIn('store_id', $ids);
            }
        });
    }

    /**
     * Demandes qu'un gérant peut voir pour les traiter (magasins où il a `swaps.view`).
     *
     * @return Builder<ShiftSwapRequest>
     */
    public function managed(User $user): Builder
    {
        $ids = $this->authorizer->storeIdsFor($user, 'swaps.view');

        return ShiftSwapRequest::query()->when($ids !== null, fn (Builder $q) => $q->whereIn('store_id', $ids ?? []));
    }

    /**
     * Demandes en attente qui concernent l'utilisateur : celles qui attendent sa réponse de collègue, celles qu'il a
     * envoyées, et, s'il approuve quelque part, celles que le collègue a acceptées dans ses magasins.
     *
     * @return Builder<ShiftSwapRequest>
     */
    public function pendingFor(User $user): Builder
    {
        $approvable = $this->authorizer->allows($user, 'swaps.approve') ? ($this->authorizer->storeIdsFor($user, 'swaps.approve') ?? true) : null;

        return ShiftSwapRequest::query()->where('status', ShiftSwapRequest::PENDING)->where(function (Builder $q) use ($user, $approvable): void {
            $q->where('requester_id', $user->id)
                ->orWhere(fn (Builder $own) => $own->where('target_user_id', $user->id)->whereNull('accepted_at'));

            if ($approvable === true) {
                $q->orWhereNotNull('accepted_at');
            } elseif (is_array($approvable)) {
                $q->orWhere(fn (Builder $managed) => $managed->whereNotNull('accepted_at')->whereIn('store_id', $approvable));
            }
        });
    }

    /**
     * Demandes qui attendent la réponse de l'employé (il est le collègue sollicité).
     *
     * @return Builder<ShiftSwapRequest>
     */
    public function awaitingAnswer(User $user): Builder
    {
        return ShiftSwapRequest::query()->where('target_user_id', $user->id)->where('status', ShiftSwapRequest::PENDING)->whereNull('accepted_at');
    }

    /**
     * Demandes à décider par un gérant : acceptées par le collègue, dans ses magasins d'approbation.
     *
     * @return Builder<ShiftSwapRequest>
     */
    public function awaitingDecision(User $user): Builder
    {
        if (! $this->authorizer->allows($user, 'swaps.approve')) {
            return ShiftSwapRequest::query()->whereRaw('1 = 0');
        }

        $ids = $this->authorizer->storeIdsFor($user, 'swaps.approve');

        return ShiftSwapRequest::query()
            ->where('status', ShiftSwapRequest::PENDING)
            ->whereNotNull('accepted_at')
            ->when($ids !== null, fn (Builder $q) => $q->whereIn('store_id', $ids ?? []));
    }

    /**
     * Shifts à venir (actifs) d'un employé dans un magasin, pour les choisir dans un échange.
     *
     * @return list<array{id: int, label: string}>
     */
    public function upcomingShifts(int $userId, int $storeId): array
    {
        return Shift::query()
            ->where('user_id', $userId)->where('store_id', $storeId)->whereNull('replaced_by_id')
            ->where('starts_at', '>', Carbon::now())
            ->orderBy('starts_at')->limit(100)->get()
            ->map(fn (Shift $shift): array => [
                'id' => $shift->id,
                'label' => $shift->shift_date->toDateString().' '.$shift->localStartsAt->format('H:i').'-'.$shift->localEndsAt->format('H:i'),
            ])->all();
    }
}
