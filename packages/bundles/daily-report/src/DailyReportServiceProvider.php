<?php

namespace Kintai\Bundles\DailyReport;

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Authenticatable;
use Kintai\Bundles\DailyReport\Console\AutoValidateCommand;
use Kintai\Bundles\DailyReport\Livewire\Form;
use Kintai\Bundles\DailyReport\Livewire\Index;
use Kintai\Bundles\DailyReport\Livewire\ManagerWidget;
use Kintai\Bundles\DailyReport\Livewire\Settings;
use Kintai\Bundles\DailyReport\Livewire\Show;
use Kintai\Bundles\DailyReport\Models\DailyReport;
use Kintai\Contracts\Bundle\BundleServiceProvider;
use Kintai\Contracts\Dashboard\DashboardWidget;
use Kintai\Contracts\Dashboard\WidgetSize;
use Kintai\Contracts\Events\UserAnonymized;
use Kintai\Contracts\Navigation\NavigationItem;
use Kintai\Contracts\Navigation\NavigationRegistry;
use Kintai\Contracts\Notifications\NotificationType;
use Kintai\Contracts\Requests\PendingRequestProvider;
use Livewire\Livewire;

/**
 * Rapports journaliers : ventes, clients, coût du personnel et pertes d'un magasin pour un jour, brouillon puis soumis puis
 * validé, avec le planning du lendemain, un PDF et un envoi par e-mail. Droits `daily_reports.view/create/update/submit/
 * approve/delete` (voir `DailyReportAccess`), fonctionnalité de magasin `daily_reports`, validation automatique planifiée.
 */
class DailyReportServiceProvider extends BundleServiceProvider
{
    public const SLUG = 'daily-report';

    public const FEATURE = 'daily_reports';

    public const SUBMITTED = 'daily_report_submitted';

    public const VALIDATED = 'daily_report_validated';

    public function slug(): string
    {
        return self::SLUG;
    }

    public function name(): string
    {
        return 'Daily reports';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function description(): string
    {
        return 'Daily activity reports per store, draft, submitted then validated, with PDF and email.';
    }

    public function requiredContract(): string
    {
        return '^1.4';
    }

    protected function bootBundle(): void
    {
        $this->commands([AutoValidateCommand::class]);

        if ($this->isEnabled()) {
            Livewire::component('daily-report.index', Index::class);
            Livewire::component('daily-report.form', Form::class);
            Livewire::component('daily-report.show', Show::class);
            Livewire::component('daily-report.settings', Settings::class);
            Livewire::component('daily-report.manager-widget', ManagerWidget::class);

            $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $schedule->command('kintai:auto-validate')->hourly()->name('kintai:auto-validate')->withoutOverlapping());
        }

        // Les gérants rédigent, soumettent et valident ; la suppression reste à l'Owner (ou à un rôle sur mesure).
        $this->permissions('daily_reports', ['view', 'create', 'update', 'submit', 'approve', 'delete'], [
            'manager' => ['view', 'create', 'update', 'submit', 'approve'],
        ]);
        $this->storeFeature(self::FEATURE, 'Daily reports');

        $hasReports = fn (Authenticatable $user): bool => $user instanceof User && $this->app->make(DailyReportAccess::class)->listableStores($user) !== [];

        $this->navigation(new NavigationItem(
            key: 'daily-report', label: 'Daily reports', route: 'daily-report.index', icon: 'document-chart-bar',
            section: NavigationRegistry::SECTION_REPORTS, order: 30, visible: $hasReports,
            current: ['daily-report.index', 'daily-report.show', 'daily-report.create', 'daily-report.edit', 'daily-report.settings'],
            badge: fn (Authenticatable $user): int => $user instanceof User ? $this->app->make(DailyReportAccess::class)->toValidate($user)->count() : 0,
        ));
        $this->pendingRequests(new PendingRequestProvider(
            'daily-report', 'Daily reports', 'daily-report.index',
            fn (Authenticatable $user): int => $user instanceof User ? $this->app->make(DailyReportAccess::class)->toValidate($user)->count() : 0,
        ));
        $this->widgets(new DashboardWidget(
            key: 'daily-report.manager', label: 'Reports to validate', audience: DashboardWidget::MANAGER, component: 'daily-report.manager-widget',
            permission: 'daily_reports.approve', storeFeature: self::FEATURE, size: WidgetSize::Medium, order: 55,
        ));
        $this->notificationTypes(
            new NotificationType(self::SUBMITTED, 'Daily report to validate', 'reports', 'document-chart-bar', push: true, referenceType: 'daily_report'),
            new NotificationType(self::VALIDATED, 'Daily report validated', 'reports', 'check-circle', push: true, referenceType: 'daily_report'),
        );
        foreach ([
            'daily_report.created' => 'Daily report created',
            'daily_report.updated' => 'Daily report updated',
            'daily_report.submitted' => 'Daily report submitted',
            'daily_report.validated' => 'Daily report validated',
            'daily_report.auto_validated' => 'Daily report validated automatically',
            'daily_report.mail_sent' => 'Daily report sent by email',
            'daily_report.deleted' => 'Daily report deleted',
            'daily_report.settings_updated' => 'Daily report settings updated',
            'daily_report.pdf_exported' => 'Daily report PDF downloaded',
        ] as $key => $label) {
            $this->activityAction($key, $label, 'Daily reports');
        }

        // Les notes sont du texte libre : effacées avec l'anonymisation de leur auteur (les chiffres du magasin restent).
        $this->listen(UserAnonymized::class, fn (UserAnonymized $event) => DailyReport::query()
            ->where('author_id', $event->userId)->update(['notes' => null]));
    }
}
