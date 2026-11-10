<?php

namespace Kintai\Bundles\Analytics;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Kintai\Bundles\Analytics\Livewire\AbsenceWidget;
use Kintai\Bundles\Analytics\Livewire\EmployeeReport;
use Kintai\Bundles\Analytics\Livewire\EmployeeStats;
use Kintai\Bundles\Analytics\Livewire\FinanceWidget;
use Kintai\Bundles\Analytics\Livewire\Profitability;
use Kintai\Bundles\Analytics\Livewire\StatsWidget;
use Kintai\Bundles\Analytics\Livewire\StoreStats;
use Kintai\Contracts\Bundle\BundleServiceProvider;
use Kintai\Contracts\Dashboard\DashboardWidget;
use Kintai\Contracts\Dashboard\WidgetSize;
use Kintai\Contracts\Employees\EmployeeAction;
use Kintai\Contracts\Navigation\NavigationItem;
use Kintai\Contracts\Navigation\NavigationRegistry;
use Kintai\Contracts\Sales\DailySalesProvider;
use Livewire\Livewire;

/**
 * Statistiques : statistiques d'un magasin et d'un employé, rapport par employé, rentabilité (ventes des rapports journaliers
 * contre coût du personnel), comparaison multi-magasins, trois widgets du tableau de bord gérant et l'export des statistiques
 * par les exports du Core. Droits : `payroll.view` et `payroll.export` du Core, avec leur portée magasin (voir
 * `AnalyticsAccess`) ; le bundle n'ajoute aucune permission.
 */
class AnalyticsServiceProvider extends BundleServiceProvider
{
    public const SLUG = 'analytics';

    public function slug(): string
    {
        return self::SLUG;
    }

    public function name(): string
    {
        return 'Analytics';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function description(): string
    {
        return 'Store and employee statistics, profitability against daily sales, and a multi-store comparison.';
    }

    public function requiredContract(): string
    {
        return '^1.6';
    }

    protected function bootBundle(): void
    {
        if ($this->isEnabled()) {
            Livewire::component('analytics.stats', StoreStats::class);
            Livewire::component('analytics.employees', EmployeeReport::class);
            Livewire::component('analytics.employee', EmployeeStats::class);
            Livewire::component('analytics.profitability', Profitability::class);
            Livewire::component('analytics.stats-widget', StatsWidget::class);
            Livewire::component('analytics.finance-widget', FinanceWidget::class);
            Livewire::component('analytics.absence-widget', AbsenceWidget::class);

            $this->exportDatasets($this->app->make(StoreStatsExport::class));
        }

        $access = fn (): AnalyticsAccess => $this->app->make(AnalyticsAccess::class);
        $canView = fn (Authenticatable $user): bool => $user instanceof User && $access()->viewableStoreIds($user) !== [];

        $this->navigation(
            new NavigationItem(
                key: 'analytics', label: 'Store statistics', route: 'analytics.stats', icon: 'chart-bar-square',
                section: NavigationRegistry::SECTION_REPORTS, order: 10, visible: $canView,
                current: ['analytics.stats', 'analytics.employee'],
            ),
            new NavigationItem(
                key: 'analytics-employees', label: 'Employee report', route: 'analytics.employees', icon: 'users',
                section: NavigationRegistry::SECTION_REPORTS, order: 20, visible: $canView,
            ),
            // Sans fournisseur de ventes (bundle daily-report), la rentabilité n'a rien à montrer : elle n'est pas proposée.
            new NavigationItem(
                key: 'analytics-profitability', label: 'Profitability', route: 'analytics.profitability', icon: 'banknotes',
                section: NavigationRegistry::SECTION_REPORTS, order: 30,
                visible: fn (Authenticatable $user): bool => $canView($user) && $this->app->make(DailySalesProvider::class)->available(),
            ),
        );

        // « Statistiques » sur la liste et la fiche des employés : ouvre la fiche statistique (`?user=`), qui revérifie les droits.
        $this->employeeActions(new EmployeeAction(
            key: 'analytics.statistics', label: 'Statistics', route: 'analytics.employee', order: 60, icon: 'chart-bar',
            visible: fn (Authenticatable $viewer, int $employeeId): bool => $viewer instanceof User && $access()->storesOfEmployee($viewer, $employeeId) !== [],
        ));

        // Les trois widgets du gérant : `payroll.view`, limités aux magasins que l'utilisateur voit.
        $this->widgets(
            new DashboardWidget(
                key: 'analytics.stats', label: 'Stats overview', audience: DashboardWidget::MANAGER, component: 'analytics.stats-widget',
                permission: AnalyticsAccess::VIEW, size: WidgetSize::Medium, refresh: 300, order: 50,
            ),
            new DashboardWidget(
                key: 'analytics.finance', label: 'Financial overview', audience: DashboardWidget::MANAGER, component: 'analytics.finance-widget',
                permission: AnalyticsAccess::VIEW, size: WidgetSize::Medium, refresh: 300, order: 60,
            ),
            new DashboardWidget(
                key: 'analytics.absence', label: 'HR and absenteeism', audience: DashboardWidget::MANAGER, component: 'analytics.absence-widget',
                permission: AnalyticsAccess::VIEW, size: WidgetSize::Small, refresh: 300, order: 70,
            ),
        );

        $this->activityAction('analytics.employee_viewed', 'Employee statistics viewed', 'Statistics');
    }
}
