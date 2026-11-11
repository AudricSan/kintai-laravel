<?php

namespace Kintai\Bundles\Timeoff;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Kintai\Bundles\Timeoff\Livewire\EmployeeWidget;
use Kintai\Bundles\Timeoff\Livewire\Manage;
use Kintai\Bundles\Timeoff\Livewire\ManagerWidget;
use Kintai\Bundles\Timeoff\Livewire\MyRequests;
use Kintai\Bundles\Timeoff\Models\TimeoffRequest;
use Kintai\Contracts\Bundle\BundleServiceProvider;
use Kintai\Contracts\Dashboard\DashboardWidget;
use Kintai\Contracts\Dashboard\WidgetSize;
use Kintai\Contracts\Events\UserAnonymized;
use Kintai\Contracts\Navigation\NavigationItem;
use Kintai\Contracts\Navigation\NavigationRegistry;
use Kintai\Contracts\Notifications\NotificationType;
use Kintai\Contracts\Planning\TimeOffChecker;
use Kintai\Contracts\Planning\TimeOffProvider;
use Kintai\Contracts\Planning\TimeOffStatistics;
use Kintai\Contracts\Requests\PendingRequestProvider;
use Livewire\Livewire;

/**
 * Congés : demandes des employés, validation par les gérants, saisie d'un congé par un gérant. Activé, le bundle apprend
 * au Core ce qu'est un congé approuvé (garde-fou du planning, flux iCal), alimente la page « Demandes » et les tableaux
 * de bord, et notifie les décisions. Droits `timeoff.view/create/update/approve/delete` (voir `TimeoffAccess`).
 */
class TimeoffServiceProvider extends BundleServiceProvider
{
    public const SLUG = 'timeoff';

    public const FEATURE = 'timeoff';

    public const SUBMITTED = 'timeoff_submitted';

    public const APPROVED = 'timeoff_approved';

    public const REFUSED = 'timeoff_refused';

    public function slug(): string
    {
        return self::SLUG;
    }

    public function name(): string
    {
        return 'Time off';
    }

    public function version(): string
    {
        return '1.1.0';
    }

    public function description(): string
    {
        return 'Time off requests: submission, approval, and approved leave taken into account by the schedule and the iCal feed.';
    }

    public function requiredContract(): string
    {
        return '^1.7';
    }

    protected function bootBundle(): void
    {
        if ($this->isEnabled()) {
            Livewire::component('timeoff.my-requests', MyRequests::class);
            Livewire::component('timeoff.manage', Manage::class);
            Livewire::component('timeoff.manager-widget', ManagerWidget::class);
            Livewire::component('timeoff.employee-widget', EmployeeWidget::class);

            // Le Core ne connaît aucun congé tant que ce bundle n'est pas là.
            $this->app->singleton(ApprovedTimeOff::class);
            $this->app->bind(TimeOffChecker::class, ApprovedTimeOff::class);
            $this->app->bind(TimeOffProvider::class, ApprovedTimeOff::class);
            $this->app->bind(TimeOffStatistics::class, LeaveStatistics::class);
        }

        // Les gérants gèrent les congés ; les employés demandent les leurs sans permission (libre-service).
        $this->permissions('timeoff', ['view', 'create', 'update', 'approve', 'delete'], [
            'manager' => ['view', 'create', 'update', 'approve', 'delete'],
        ]);
        $this->storeFeature(self::FEATURE, 'Time off');

        $hasStore = fn (Authenticatable $user): bool => $user instanceof User && $this->app->make(TimeoffAccess::class)->memberStores($user) !== [];

        $this->navigation(
            new NavigationItem(
                key: 'timeoff', label: 'My time off', route: 'timeoff.index', icon: 'sun',
                section: NavigationRegistry::SECTION_REQUESTS, order: 20, visible: $hasStore, current: ['timeoff.index'],
            ),
            new NavigationItem(
                key: 'timeoff-manage', label: 'Time off requests', route: 'timeoff.manage', icon: 'clipboard-document-check',
                section: NavigationRegistry::SECTION_REQUESTS, order: 21, permission: 'timeoff.view', current: ['timeoff.manage'],
            ),
        );
        $this->pendingRequests(new PendingRequestProvider(
            'timeoff', 'Time off', 'timeoff.index',
            fn (Authenticatable $user): int => $user instanceof User ? $this->app->make(TimeoffAccess::class)->pendingFor($user)->count() : 0,
        ));
        $this->widgets(
            new DashboardWidget(
                key: 'timeoff.manager', label: 'Pending time off', audience: DashboardWidget::MANAGER, component: 'timeoff.manager-widget',
                permission: 'timeoff.view', storeFeature: self::FEATURE, size: WidgetSize::Medium, order: 50,
            ),
            new DashboardWidget(
                key: 'timeoff.employee', label: 'My pending time off', audience: DashboardWidget::EMPLOYEE, component: 'timeoff.employee-widget',
                storeFeature: self::FEATURE, size: WidgetSize::Small, order: 50,
            ),
        );
        $this->notificationTypes(
            new NotificationType(self::SUBMITTED, 'Time off request pending', 'requests', 'sun', push: true, referenceType: 'timeoff'),
            new NotificationType(self::APPROVED, 'Time off approved', 'requests', 'check-circle', push: true, mail: true, referenceType: 'timeoff'),
            new NotificationType(self::REFUSED, 'Time off refused', 'requests', 'x-circle', push: true, mail: true, referenceType: 'timeoff'),
        );
        foreach ([
            'timeoff.created' => 'Time off requested',
            'timeoff.created_by_manager' => 'Time off added by a manager',
            'timeoff.approved' => 'Time off approved',
            'timeoff.refused' => 'Time off refused',
            'timeoff.cancelled' => 'Time off cancelled',
            'timeoff.updated' => 'Time off updated',
            'timeoff.deleted' => 'Time off deleted',
        ] as $key => $label) {
            $this->activityAction($key, $label, 'Time off');
        }

        // Les motifs et notes sont des données personnelles : effacés avec l'anonymisation du compte (les dates restent).
        $this->listen(UserAnonymized::class, fn (UserAnonymized $event) => TimeoffRequest::query()
            ->where('user_id', $event->userId)->update(['reason' => null, 'admin_note' => null]));
    }
}
