<?php

namespace Kintai\Bundles\PlanningCheck;

use App\Authorization\Authorizer;
use App\Models\Store;
use App\Models\StoreFeature;
use App\Models\StoreMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Kintai\Bundles\PlanningCheck\Models\PlanningCheck;
use Kintai\Bundles\PlanningCheck\Models\PlanningCheckSetting;

/**
 * Qui consulte, soumet, valide et règle quelle validation. Tout passe par la fonctionnalité de magasin `planning_check`
 * et la portée magasin du Core (`Authorizer`) :
 *
 * - `planning_check.view` : consulter l'historique du magasin ;
 * - `planning_check.submit` : confirmer que le planning de demain est correct, rouvrir la vérification ;
 * - `planning_check.approve` : valider (le PDF part), refuser, renvoyer, envoyer maintenant ;
 * - `planning_check.settings` : régler le magasin.
 *
 * Soumettre, valider ou régler donne aussi accès à l'écran et à l'historique du magasin.
 */
class PlanningCheckAccess
{
    public function __construct(private readonly Authorizer $authorizer) {}

    /**
     * Magasins actifs où la fonctionnalité est activée, nom par identifiant.
     *
     * @param  list<int>|null  $only
     * @return array<int, string>
     */
    public function activeStores(?array $only = null): array
    {
        $query = Store::query()
            ->where('is_active', true)
            ->whereNotIn('id', StoreFeature::query()->where('feature', PlanningCheckServiceProvider::FEATURE)->where('enabled', false)->select('store_id'))
            ->orderBy('name');

        if ($only !== null) {
            $query->whereIn('id', $only);
        }

        $stores = [];
        foreach ($query->get(['id', 'name']) as $store) {
            $stores[$store->id] = $store->name;
        }

        return $stores;
    }

    /**
     * Magasins où l'utilisateur a la permission (avec la fonctionnalité).
     *
     * @return array<int, string>
     */
    public function storesWith(User $user, string $permission): array
    {
        $ids = $this->authorizer->storeIdsFor($user, $permission);

        return $ids === null ? $this->activeStores() : $this->activeStores($ids);
    }

    /**
     * Magasins dont l'utilisateur ouvre l'écran : il peut y consulter, soumettre, valider ou régler.
     *
     * @return array<int, string>
     */
    public function visibleStores(User $user): array
    {
        $stores = [];
        foreach (['view', 'submit', 'approve', 'settings'] as $action) {
            $stores += $this->storesWith($user, 'planning_check.'.$action);
        }
        asort($stores);

        return $stores;
    }

    public function allows(User $user, string $permission, int $storeId): bool
    {
        return isset($this->activeStores([$storeId])[$storeId]) && $this->authorizer->allows($user, $permission, $storeId);
    }

    public function canView(User $user, int $storeId): bool
    {
        return isset($this->visibleStores($user)[$storeId]);
    }

    /**
     * Les validations que l'utilisateur peut consulter.
     *
     * @return Builder<PlanningCheck>
     */
    public function visible(User $user): Builder
    {
        return PlanningCheck::query()->whereIn('store_id', array_keys($this->visibleStores($user)));
    }

    /**
     * Validations soumises qui attendent la décision de l'utilisateur.
     *
     * @return Builder<PlanningCheck>
     */
    public function toValidate(User $user): Builder
    {
        return PlanningCheck::query()->where('status', PlanningCheck::SUBMITTED)->whereIn('store_id', array_keys($this->storesWith($user, 'planning_check.approve')));
    }

    /**
     * Validations de demain que l'utilisateur doit soumettre : activées, jour travaillé, fenêtre ouverte, pas encore soumises.
     */
    public function toSubmit(User $user): int
    {
        $date = PlanningCheckPolicy::targetDate();
        $count = 0;

        foreach (array_keys($this->storesWith($user, 'planning_check.submit')) as $storeId) {
            $setting = PlanningCheckSetting::forStore($storeId);
            if (! PlanningCheckPolicy::isDue($setting, $date)) {
                continue;
            }

            $status = PlanningCheck::query()->where('store_id', $storeId)->whereDate('target_date', $date)->value('status');
            if ($status === null || in_array($status, PlanningCheck::OPEN, true)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Nombre de choses à faire pour l'utilisateur : validations à approuver et plannings de demain à soumettre.
     */
    public function attention(User $user): int
    {
        return $this->toValidate($user)->count() + $this->toSubmit($user);
    }

    /**
     * Utilisateurs actifs qui peuvent valider le magasin : les membres détenteurs de `planning_check.approve` et les Owners.
     *
     * @return list<User>
     */
    public function approvers(int $storeId): array
    {
        $members = StoreMembership::query()->where('store_id', $storeId)->where('is_active', true)->pluck('user_id')->all();
        $ids = array_unique([...$members, ...$this->authorizer->activeOwnerIds()]);
        $approvers = [];

        foreach (User::query()->whereIn('id', $ids)->where('is_active', true)->get() as $candidate) {
            if ($this->authorizer->allows($candidate, 'planning_check.approve', $storeId)) {
                $approvers[] = $candidate;
            }
        }

        return $approvers;
    }
}
