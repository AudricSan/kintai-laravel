<?php

namespace Kintai\Bundles\SalaryReport;

use App\Authorization\Authorizer;
use App\Models\Store;
use App\Models\StoreFeature;
use App\Models\StoreMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Kintai\Bundles\SalaryReport\Models\SalaryReport;

/**
 * Qui voit, génère, exporte et supprime quels rapports de salaire. Les salaires sont très sensibles : aucun droit n'est
 * implicite (pas de « voir les siens »), chaque action a sa permission, évaluée avec la portée magasin du Core
 * (`Authorizer`) et la fonctionnalité de magasin `salary_reports` :
 *
 * - `salary_reports.view` : lister et ouvrir les rapports du magasin ;
 * - `salary_reports.generate` : calculer, créer et modifier un rapport ;
 * - `salary_reports.export` : PDF et exports (en plus de `view` : on n'exporte que ce qu'on peut voir) ;
 * - `salary_reports.delete` : supprimer ;
 * - `salary_reports.settings` : retenues du magasin et employés assujettis.
 */
class SalaryReportAccess
{
    public function __construct(private readonly Authorizer $authorizer) {}

    /**
     * Magasins actifs où la fonctionnalité est activée, nom par identifiant.
     *
     * @param  list<int>|null  $only  Restreint aux magasins donnés.
     * @return array<int, string>
     */
    public function activeStores(?array $only = null): array
    {
        $query = Store::query()
            ->where('is_active', true)
            ->whereNotIn('id', StoreFeature::query()->where('feature', SalaryReportServiceProvider::FEATURE)->where('enabled', false)->select('store_id'))
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
     * Magasins dont l'utilisateur peut exporter les rapports : `view` et `export` à la fois.
     *
     * @return array<int, string>
     */
    public function exportableStores(User $user): array
    {
        return array_intersect_key($this->storesWith($user, 'salary_reports.view'), $this->storesWith($user, 'salary_reports.export'));
    }

    public function featureEnabled(int $storeId): bool
    {
        return isset($this->activeStores([$storeId])[$storeId]);
    }

    /**
     * Permission du Core sur un magasin où la fonctionnalité est activée.
     */
    public function allows(User $user, string $permission, int $storeId): bool
    {
        return $this->featureEnabled($storeId) && $this->authorizer->allows($user, $permission, $storeId);
    }

    public function canView(User $user, SalaryReport $report): bool
    {
        return $this->allows($user, 'salary_reports.view', $report->store_id);
    }

    public function canGenerate(User $user, int $storeId): bool
    {
        return $this->allows($user, 'salary_reports.generate', $storeId);
    }

    public function canEdit(User $user, SalaryReport $report): bool
    {
        return $this->canView($user, $report) && $this->allows($user, 'salary_reports.generate', $report->store_id);
    }

    public function canDelete(User $user, SalaryReport $report): bool
    {
        return $this->canView($user, $report) && $this->allows($user, 'salary_reports.delete', $report->store_id);
    }

    public function canExport(User $user, SalaryReport $report): bool
    {
        return $this->canView($user, $report) && $this->allows($user, 'salary_reports.export', $report->store_id);
    }

    public function canManageSettings(User $user, int $storeId): bool
    {
        return $this->allows($user, 'salary_reports.settings', $storeId);
    }

    /**
     * Rapports que l'utilisateur peut ouvrir : ceux des magasins où il a `view`, et seulement ceux-là.
     *
     * @return Builder<SalaryReport>
     */
    public function visible(User $user): Builder
    {
        return SalaryReport::query()->whereIn('store_id', array_keys($this->storesWith($user, 'salary_reports.view')));
    }

    public function isOwner(User $user): bool
    {
        return $this->authorizer->isOwner($user);
    }

    /**
     * Employés actifs du magasin, nom par identifiant.
     *
     * @return array<int, string>
     */
    public function members(int $storeId): array
    {
        $ids = StoreMembership::query()->where('store_id', $storeId)->where('is_active', true)->pluck('user_id');
        $members = [];

        foreach (User::query()->whereIn('id', $ids)->where('is_active', true)->orderBy('name')->get(['id', 'name']) as $user) {
            $members[$user->id] = $user->name;
        }

        return $members;
    }

    /**
     * Responsables possibles d'un rapport du magasin : les Owners actifs et les membres actifs qui peuvent générer des
     * rapports dans ce magasin.
     *
     * @return array<int, string>
     */
    public function managers(int $storeId): array
    {
        $candidates = User::query()->where('is_active', true)
            ->where(function (Builder $query) use ($storeId): void {
                $query->whereIn('id', $this->authorizer->activeOwnerIds())
                    ->orWhereIn('id', StoreMembership::query()->where('store_id', $storeId)->where('is_active', true)->select('user_id'));
            })
            ->orderBy('name')
            ->get(['id', 'name']);

        $managers = [];
        foreach ($candidates as $candidate) {
            if ($this->authorizer->allows($candidate, 'salary_reports.generate', $storeId)) {
                $managers[$candidate->id] = $candidate->name;
            }
        }

        return $managers;
    }
}
