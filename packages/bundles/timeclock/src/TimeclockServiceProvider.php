<?php

namespace Kintai\Bundles\Timeclock;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Kintai\Bundles\Timeclock\Livewire\EmployeeWidget;
use Kintai\Bundles\Timeclock\Livewire\Manage;
use Kintai\Bundles\Timeclock\Livewire\ManagerWidget;
use Kintai\Bundles\Timeclock\Livewire\MyClock;
use Kintai\Bundles\Timeclock\Models\Timeclock;
use Kintai\Contracts\Bundle\BundleServiceProvider;
use Kintai\Contracts\Dashboard\DashboardWidget;
use Kintai\Contracts\Dashboard\WidgetSize;
use Kintai\Contracts\Events\UserAnonymized;
use Kintai\Contracts\Navigation\NavigationItem;
use Kintai\Contracts\Navigation\NavigationRegistry;
use Kintai\Contracts\Settings\SettingDefinition;
use Livewire\Livewire;

/**
 * Pointage : l'employé pointe son entrée et sa sortie (rattachées au shift du jour), les gérants consultent, corrigent et
 * exportent les pointages de leurs magasins. Droits `timeclock.view/update/delete` (voir `TimeclockAccess`).
 */
class TimeclockServiceProvider extends BundleServiceProvider
{
    public const SLUG = 'timeclock';

    public const FEATURE = 'timeclock';

    public function slug(): string
    {
        return self::SLUG;
    }

    public function name(): string
    {
        return 'Time clock';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function description(): string
    {
        return 'Employee clock-in and clock-out tied to their shift, history, and management of time clock entries.';
    }

    protected function bootBundle(): void
    {
        if ($this->isEnabled()) {
            Livewire::component('timeclock.my-clock', MyClock::class);
            Livewire::component('timeclock.manage', Manage::class);
            Livewire::component('timeclock.manager-widget', ManagerWidget::class);
            Livewire::component('timeclock.employee-widget', EmployeeWidget::class);
        }

        // Les gérants consultent et corrigent ; les employés pointent et lisent leurs pointages sans permission.
        $this->permissions('timeclock', ['view', 'update', 'delete'], ['manager' => ['view', 'update', 'delete']]);
        $this->storeFeature(self::FEATURE, 'Time clock');
        $this->settings(new SettingDefinition('timeclock.late_threshold_minutes', SettingDefinition::TYPE_INT, 5, 0, 240));

        $hasStore = fn (Authenticatable $user): bool => $user instanceof User && $this->app->make(TimeclockAccess::class)->memberStores($user) !== [];

        $this->navigation(
            new NavigationItem(
                key: 'timeclock', label: 'Time clock', route: 'timeclock.index', icon: 'clock',
                section: NavigationRegistry::SECTION_PLANNING, order: 70, visible: $hasStore, current: ['timeclock.index'],
            ),
            new NavigationItem(
                key: 'timeclock-manage', label: 'Time clock entries', route: 'timeclock.manage', icon: 'clipboard-document-list',
                section: NavigationRegistry::SECTION_PLANNING, order: 71, permission: 'timeclock.view', current: ['timeclock.manage'],
            ),
        );
        $this->widgets(
            new DashboardWidget(
                key: 'timeclock.manager', label: 'Active clock-ins', audience: DashboardWidget::MANAGER, component: 'timeclock.manager-widget',
                permission: 'timeclock.view', storeFeature: self::FEATURE, size: WidgetSize::Medium, refresh: 60, order: 60,
            ),
            new DashboardWidget(
                key: 'timeclock.employee', label: 'Time clock', audience: DashboardWidget::EMPLOYEE, component: 'timeclock.employee-widget',
                storeFeature: self::FEATURE, size: WidgetSize::Small, order: 5,
            ),
        );
        $this->exportDatasets(new TimeclockExport);
        foreach ([
            'timeclock.clock_in' => 'Clock-in',
            'timeclock.clock_out' => 'Clock-out',
            'timeclock.edited' => 'Time clock entry edited',
            'timeclock.deleted' => 'Time clock entry deleted',
        ] as $key => $label) {
            $this->activityAction($key, $label, 'Time clock');
        }

        // Les adresses IP et les notes sont des données personnelles : effacées avec l'anonymisation (les heures restent, pour la paie).
        $this->listen(UserAnonymized::class, fn (UserAnonymized $event) => Timeclock::query()
            ->where('user_id', $event->userId)->update(['clock_in_ip' => null, 'clock_out_ip' => null, 'notes' => null]));
    }
}
