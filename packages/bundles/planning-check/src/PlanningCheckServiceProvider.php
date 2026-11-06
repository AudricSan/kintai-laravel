<?php

namespace Kintai\Bundles\PlanningCheck;

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Authenticatable;
use Kintai\Bundles\PlanningCheck\Console\EscalateCommand;
use Kintai\Bundles\PlanningCheck\Livewire\Index;
use Kintai\Bundles\PlanningCheck\Livewire\ManagerWidget;
use Kintai\Bundles\PlanningCheck\Livewire\Settings;
use Kintai\Contracts\Bundle\BundleServiceProvider;
use Kintai\Contracts\Dashboard\DashboardWidget;
use Kintai\Contracts\Dashboard\WidgetSize;
use Kintai\Contracts\Navigation\NavigationItem;
use Kintai\Contracts\Navigation\NavigationRegistry;
use Kintai\Contracts\Notifications\NotificationType;
use Kintai\Contracts\Requests\PendingRequestProvider;
use Livewire\Livewire;

/**
 * Validation du planning de demain : avant l'heure limite, le gérant confirme que les shifts du lendemain sont corrects,
 * un valideur approuve et le PDF part par e-mail ; sans soumission à l'heure limite, les valideurs et les Owners sont
 * prévenus. Droits `planning_check.view/submit/approve/settings` (voir `PlanningCheckAccess`), fonctionnalité de magasin
 * `planning_check`, escalade planifiée.
 */
class PlanningCheckServiceProvider extends BundleServiceProvider
{
    public const SLUG = 'planning-check';

    public const FEATURE = 'planning_check';

    public const SUBMITTED = 'planning_check_submitted';

    public const VALIDATED = 'planning_check_validated';

    public const REJECTED = 'planning_check_rejected';

    public const OVERDUE = 'planning_check_overdue';

    public const FAILED = 'planning_check_failed';

    public function slug(): string
    {
        return self::SLUG;
    }

    public function name(): string
    {
        return 'Schedule validation';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function description(): string
    {
        return 'Before the deadline, the manager confirms tomorrow\'s shifts; once validated, the PDF is emailed.';
    }

    public function requiredContract(): string
    {
        return '^1.4';
    }

    protected function bootBundle(): void
    {
        $this->commands([EscalateCommand::class]);

        if ($this->isEnabled()) {
            Livewire::component('planning-check.index', Index::class);
            Livewire::component('planning-check.settings', Settings::class);
            Livewire::component('planning-check.manager-widget', ManagerWidget::class);

            $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $schedule->command('kintai:planning-check-escalate')->everyFifteenMinutes()->name('kintai:planning-check-escalate')->withoutOverlapping());
        }

        // Les gérants consultent, soumettent et valident ; les réglages (destinataires du PDF) restent à l'Owner.
        $this->permissions('planning_check', ['view', 'submit', 'approve', 'settings'], [
            'manager' => ['view', 'submit', 'approve'],
        ]);
        $this->storeFeature(self::FEATURE, 'Schedule validation');

        $access = fn (): PlanningCheckAccess => $this->app->make(PlanningCheckAccess::class);

        $this->navigation(new NavigationItem(
            key: 'planning-check', label: 'Tomorrow\'s schedule', route: 'planning-check.index', icon: 'clipboard-document-check',
            section: NavigationRegistry::SECTION_PLANNING, order: 35,
            visible: fn (Authenticatable $user): bool => $user instanceof User && $access()->visibleStores($user) !== [],
            current: ['planning-check.index', 'planning-check.settings'],
            badge: fn (Authenticatable $user): int => $user instanceof User ? $access()->attention($user) : 0,
        ));
        $this->pendingRequests(new PendingRequestProvider(
            'planning-check', 'Tomorrow\'s schedule', 'planning-check.index',
            fn (Authenticatable $user): int => $user instanceof User ? $access()->attention($user) : 0,
        ));
        $this->widgets(new DashboardWidget(
            key: 'planning-check.manager', label: 'Tomorrow\'s schedule', audience: DashboardWidget::MANAGER, component: 'planning-check.manager-widget',
            permission: 'planning_check.view', storeFeature: self::FEATURE, size: WidgetSize::Medium, order: 40,
        ));
        $this->notificationTypes(
            new NotificationType(self::SUBMITTED, 'Schedule to validate', 'reports', 'clipboard-document-check', push: true, referenceType: 'planning_check'),
            new NotificationType(self::VALIDATED, 'Schedule validated', 'reports', 'check-circle', push: true, referenceType: 'planning_check'),
            new NotificationType(self::REJECTED, 'Schedule refused', 'reports', 'arrow-uturn-left', push: true, referenceType: 'planning_check'),
            new NotificationType(self::OVERDUE, 'Tomorrow\'s schedule not submitted', 'reports', 'clock', push: true, referenceType: 'planning_check'),
            new NotificationType(self::FAILED, 'Schedule sending failed', 'reports', 'exclamation-triangle', push: true, referenceType: 'planning_check'),
        );
        foreach ([
            'planning_check.submitted' => 'Schedule submitted',
            'planning_check.validated' => 'Schedule validated',
            'planning_check.rejected' => 'Schedule refused',
            'planning_check.escalated' => 'Schedule overdue',
            'planning_check.sent' => 'Schedule sent by email',
            'planning_check.resent' => 'Schedule sent again by email',
            'planning_check.send_failed' => 'Schedule email failed',
            'planning_check.reopened' => 'Schedule check reopened',
            'planning_check.settings_updated' => 'Schedule validation settings updated',
            'planning_check.test_sent' => 'Schedule test email sent',
        ] as $key => $label) {
            $this->activityAction($key, $label, 'Schedule validation');
        }
    }
}
