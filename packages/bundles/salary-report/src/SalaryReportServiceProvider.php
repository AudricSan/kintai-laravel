<?php

namespace Kintai\Bundles\SalaryReport;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Kintai\Bundles\SalaryReport\Livewire\Form;
use Kintai\Bundles\SalaryReport\Livewire\Index;
use Kintai\Bundles\SalaryReport\Livewire\Settings;
use Kintai\Bundles\SalaryReport\Livewire\Show;
use Kintai\Bundles\SalaryReport\Models\SalaryReport;
use Kintai\Contracts\Bundle\BundleServiceProvider;
use Kintai\Contracts\Events\UserAnonymized;
use Kintai\Contracts\Navigation\NavigationItem;
use Kintai\Contracts\Navigation\NavigationRegistry;
use Kintai\Contracts\Stores\StoreAction;
use Livewire\Livewire;

/**
 * Rapports de salaire : le rapport d'un mois pour un magasin ou pour un employé, calculé depuis les shifts (heures, coût
 * par `ShiftWageCalculator`) et les retenues du magasin, avec PDF et exports. Données très sensibles : droits dédiés par
 * action `salary_reports.view/generate/export/delete/settings` (voir `SalaryReportAccess`), fonctionnalité de magasin
 * `salary_reports`, rien de public.
 */
class SalaryReportServiceProvider extends BundleServiceProvider
{
    public const SLUG = 'salary-report';

    public const FEATURE = 'salary_reports';

    public function slug(): string
    {
        return self::SLUG;
    }

    public function name(): string
    {
        return 'Salary reports';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function description(): string
    {
        return 'Monthly salary reports per store or employee, calculated from the shifts, with deductions, PDF and exports.';
    }

    public function requiredContract(): string
    {
        return '^1.7';
    }

    protected function bootBundle(): void
    {
        if ($this->isEnabled()) {
            Livewire::component('salary-report.index', Index::class);
            Livewire::component('salary-report.form', Form::class);
            Livewire::component('salary-report.show', Show::class);
            Livewire::component('salary-report.settings', Settings::class);
        }

        // Comme `payroll.*` dans l'ancienne version, les gérants consultent, génèrent, exportent et suppriment, et règlent
        // les retenues de leur magasin (qu'ils réglaient avec `stores.update`) ; un employé n'a aucun droit par défaut.
        $this->permissions('salary_reports', ['view', 'generate', 'export', 'delete', 'settings'], [
            'manager' => ['view', 'generate', 'export', 'delete', 'settings'],
        ]);
        $this->storeFeature(self::FEATURE, 'Salary reports');

        $this->navigation(new NavigationItem(
            key: 'salary-report', label: 'Salary reports', route: 'salary-report.index', icon: 'banknotes',
            section: NavigationRegistry::SECTION_REPORTS, order: 50,
            visible: fn (Authenticatable $user): bool => $user instanceof User && $this->app->make(SalaryReportAccess::class)->storesWith($user, 'salary_reports.view') !== [],
            current: ['salary-report.index', 'salary-report.show', 'salary-report.create', 'salary-report.edit', 'salary-report.settings'],
        ));

        // « Retenues » sur la liste des magasins : ouvre les réglages du magasin (`?store=`), qui revérifient les droits.
        $this->storeActions(new StoreAction(
            key: 'salary-report.settings', label: 'Deductions', route: 'salary-report.settings', order: 80, icon: 'cog-6-tooth',
            visible: fn (Authenticatable $viewer, int $storeId): bool => $viewer instanceof User && $this->app->make(SalaryReportAccess::class)->canManageSettings($viewer, $storeId),
        ));

        foreach ([
            'salary_report.created' => 'Salary report created',
            'salary_report.updated' => 'Salary report updated',
            'salary_report.deleted' => 'Salary report deleted',
            'salary_report.viewed' => 'Salary report viewed',
            'salary_report.settings_updated' => 'Salary report settings updated',
            'salary_report.deduction_saved' => 'Custom deduction saved',
            'salary_report.deduction_deleted' => 'Custom deduction deleted',
            'salary_report.pdf_exported' => 'Salary report PDF downloaded',
            'salary_report.list_exported' => 'Salary reports exported',
        ] as $key => $label) {
            $this->activityAction($key, $label, 'Salary reports');
        }

        // Les textes libres du rapport d'un employé anonymisé (embauche, remarques) sont effacés ; les chiffres restent.
        $this->listen(UserAnonymized::class, fn (UserAnonymized $event) => SalaryReport::query()
            ->where('user_id', $event->userId)->update(['hire_registrations' => null, 'remarks' => null]));
    }
}
