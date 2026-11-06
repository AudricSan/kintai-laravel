<?php

namespace Kintai\Bundles\ResignationReport;

use App\Authorization\Authorizer;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Kintai\Bundles\ResignationReport\Models\ResignationReport;

/**
 * Qui voit, crée, modifie et supprime quels rapports de démission. Le contenu est personnel : aucun droit n'est implicite
 * (pas de « voir le sien »), chaque action a sa permission, évaluée avec la portée magasin du Core (`Authorizer`) :
 *
 * - `resignation_reports.view` : lister, ouvrir et obtenir le PDF des rapports du magasin ;
 * - `resignation_reports.create` : créer un rapport ;
 * - `resignation_reports.update` : modifier (en plus de `view` : on ne modifie que ce qu'on peut voir) ;
 * - `resignation_reports.delete` : supprimer (en plus de `view`).
 */
class ResignationReportAccess
{
    public function __construct(private readonly Authorizer $authorizer) {}

    /**
     * Magasins actifs, nom par identifiant.
     *
     * @param  list<int>|null  $only  Restreint aux magasins donnés.
     * @return array<int, string>
     */
    public function activeStores(?array $only = null): array
    {
        $query = Store::query()->where('is_active', true)->orderBy('name');

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
     * Magasins où l'utilisateur a la permission.
     *
     * @return array<int, string>
     */
    public function storesWith(User $user, string $permission): array
    {
        $ids = $this->authorizer->storeIdsFor($user, $permission);

        return $ids === null ? $this->activeStores() : $this->activeStores($ids);
    }

    /**
     * Permission du Core sur un magasin actif.
     */
    public function allows(User $user, string $permission, int $storeId): bool
    {
        return isset($this->activeStores([$storeId])[$storeId]) && $this->authorizer->allows($user, $permission, $storeId);
    }

    public function canView(User $user, ResignationReport $report): bool
    {
        return $this->allows($user, 'resignation_reports.view', $report->store_id);
    }

    public function canCreate(User $user, int $storeId): bool
    {
        return $this->allows($user, 'resignation_reports.create', $storeId);
    }

    public function canUpdate(User $user, ResignationReport $report): bool
    {
        return $this->canView($user, $report) && $this->allows($user, 'resignation_reports.update', $report->store_id);
    }

    public function canDelete(User $user, ResignationReport $report): bool
    {
        return $this->canView($user, $report) && $this->allows($user, 'resignation_reports.delete', $report->store_id);
    }

    /**
     * Peut modifier cet employé dans ce magasin (`employees.update` du Core, portée magasin) : condition pour lier un
     * rapport à son compte, puisque le rapport le désactive à la date de départ.
     */
    public function canManageEmployee(User $user, int $storeId, int $employeeId): bool
    {
        $employee = User::query()->find($employeeId);

        return $employee !== null
            && $this->allows($user, 'employees.update', $storeId)
            && $this->authorizer->canActOnUser($user, 'employees.update', $employee);
    }

    /**
     * L'utilisateur peut créer un rapport dans un magasin de l'employé : condition d'affichage de l'action « Licencier »
     * (un raccourci : le formulaire et l'enregistrement revérifient tout).
     */
    public function canCreateFor(User $user, int $employeeId): bool
    {
        if ($user->id === $employeeId) {
            return false;
        }

        foreach (array_keys($this->storesWith($user, 'resignation_reports.create')) as $storeId) {
            if (isset($this->members($storeId)[$employeeId])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rapports que l'utilisateur peut ouvrir : ceux des magasins où il a `view`, et seulement ceux-là.
     *
     * @return Builder<ResignationReport>
     */
    public function visible(User $user): Builder
    {
        return ResignationReport::query()->whereIn('store_id', array_keys($this->storesWith($user, 'resignation_reports.view')));
    }

    /**
     * Employés du magasin, nom par identifiant.
     *
     * @return array<int, string>
     */
    public function members(int $storeId): array
    {
        $ids = StoreMembership::query()->where('store_id', $storeId)->pluck('user_id');
        $members = [];

        foreach (User::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name']) as $user) {
            $members[$user->id] = $user->name;
        }

        return $members;
    }
}
