<?php

namespace Kintai\Bundles\DailyReport;

use App\Authorization\Authorizer;
use App\Models\Store;
use App\Models\StoreFeature;
use App\Models\StoreMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Kintai\Bundles\DailyReport\Models\DailyReport;

/**
 * Qui voit, rédige, soumet et valide quels rapports. Tout passe par la fonctionnalité de magasin `daily_reports` et la
 * portée magasin du Core (`Authorizer`) :
 *
 * - `daily_reports.view` : voir tous les rapports du magasin (sinon, un membre du magasin voit les siens) ;
 * - `daily_reports.create` : rédiger le rapport du jour, et modifier son propre brouillon ;
 * - `daily_reports.update` : modifier un brouillon ou un rapport soumis, régler le magasin ;
 * - `daily_reports.submit` : soumettre un brouillon (créer sans soumettre est possible) ;
 * - `daily_reports.approve` : valider, envoyer le rapport validé par e-mail ;
 * - `daily_reports.delete` : supprimer.
 */
class DailyReportAccess
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
            ->whereNotIn('id', StoreFeature::query()->where('feature', DailyReportServiceProvider::FEATURE)->where('enabled', false)->select('store_id'))
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
     * Magasins dont l'utilisateur est membre actif (avec la fonctionnalité).
     *
     * @return array<int, string>
     */
    public function memberStores(User $user): array
    {
        return $this->activeStores(StoreMembership::query()->where('user_id', $user->id)->where('is_active', true)->pluck('store_id')->map(fn ($id): int => (int) $id)->all());
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
     * Magasins dont l'utilisateur ouvre la liste des rapports : ceux où il a `view`, et ceux dont il est membre.
     *
     * @return array<int, string>
     */
    public function listableStores(User $user): array
    {
        return $this->storesWith($user, 'daily_reports.view') + $this->memberStores($user);
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

    public function canCreate(User $user, int $storeId): bool
    {
        return $this->featureEnabled($storeId) && $this->authorizer->allows($user, 'daily_reports.create', $storeId);
    }

    public function canViewAll(User $user, int $storeId): bool
    {
        return $this->featureEnabled($storeId) && $this->authorizer->allows($user, 'daily_reports.view', $storeId);
    }

    public function canView(User $user, DailyReport $report): bool
    {
        if ($this->canViewAll($user, $report->store_id)) {
            return true;
        }

        // Sans la permission de lecture, un membre ne voit que ses propres rapports.
        return $report->author_id === $user->id && isset($this->memberStores($user)[$report->store_id]);
    }

    public function canEdit(User $user, DailyReport $report): bool
    {
        if ($report->status === DailyReport::VALIDATED || ! $this->featureEnabled($report->store_id)) {
            return false;
        }

        if ($this->authorizer->allows($user, 'daily_reports.update', $report->store_id)) {
            return true;
        }

        return $report->status === DailyReport::DRAFT && $report->author_id === $user->id && $this->authorizer->allows($user, 'daily_reports.create', $report->store_id);
    }

    public function canSubmit(User $user, DailyReport $report): bool
    {
        return $report->status === DailyReport::DRAFT && $this->featureEnabled($report->store_id) && $this->authorizer->allows($user, 'daily_reports.submit', $report->store_id);
    }

    public function canValidate(User $user, DailyReport $report): bool
    {
        return $report->status === DailyReport::SUBMITTED && $this->featureEnabled($report->store_id) && $this->authorizer->allows($user, 'daily_reports.approve', $report->store_id);
    }

    public function canSendMail(User $user, DailyReport $report): bool
    {
        return $report->status === DailyReport::VALIDATED && $this->featureEnabled($report->store_id) && $this->authorizer->allows($user, 'daily_reports.approve', $report->store_id);
    }

    public function canDelete(User $user, DailyReport $report): bool
    {
        return $this->featureEnabled($report->store_id) && $this->authorizer->allows($user, 'daily_reports.delete', $report->store_id);
    }

    public function canManageSettings(User $user, int $storeId): bool
    {
        return $this->featureEnabled($storeId) && $this->authorizer->allows($user, 'daily_reports.update', $storeId);
    }

    /**
     * Rapports que l'utilisateur peut ouvrir : tous ceux des magasins où il a `view`, les siens ailleurs.
     *
     * @return Builder<DailyReport>
     */
    public function visible(User $user): Builder
    {
        $all = array_keys($this->storesWith($user, 'daily_reports.view'));
        $own = array_keys($this->memberStores($user));

        return DailyReport::query()->where(function (Builder $query) use ($all, $own, $user): void {
            $query->whereIn('store_id', $all)
                ->orWhere(fn (Builder $q) => $q->where('author_id', $user->id)->whereIn('store_id', $own));
        });
    }

    /**
     * Rapports soumis qui attendent la validation de l'utilisateur.
     *
     * @return Builder<DailyReport>
     */
    public function toValidate(User $user): Builder
    {
        return DailyReport::query()
            ->where('status', DailyReport::SUBMITTED)
            ->whereIn('store_id', array_keys($this->storesWith($user, 'daily_reports.approve')));
    }

    public function isOwner(User $user): bool
    {
        return $this->authorizer->isOwner($user);
    }

    /**
     * Utilisateurs actifs, membres du magasin, qui ont la permission : destinataires des notifications.
     *
     * @return list<User>
     */
    public function holders(int $storeId, string $permission): array
    {
        $members = StoreMembership::query()->where('store_id', $storeId)->where('is_active', true)->pluck('user_id');
        $holders = [];

        foreach (User::query()->whereIn('id', $members)->where('is_active', true)->get() as $candidate) {
            if ($this->authorizer->allows($candidate, $permission, $storeId)) {
                $holders[] = $candidate;
            }
        }

        return $holders;
    }
}
