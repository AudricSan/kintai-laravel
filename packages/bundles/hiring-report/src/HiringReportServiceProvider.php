<?php

namespace Kintai\Bundles\HiringReport;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Kintai\Bundles\HiringReport\Livewire\Form;
use Kintai\Bundles\HiringReport\Livewire\Index;
use Kintai\Bundles\HiringReport\Livewire\Show;
use Kintai\Bundles\HiringReport\Models\HiringReport;
use Kintai\Contracts\Bundle\BundleServiceProvider;
use Kintai\Contracts\Events\EmployeeCreated;
use Kintai\Contracts\Events\UserAnonymized;
use Kintai\Contracts\Navigation\NavigationItem;
use Kintai\Contracts\Navigation\NavigationRegistry;
use Livewire\Livewire;

/**
 * Rapports d'embauche : la fiche d'embauche d'un employé dans un magasin (identité, coordonnées, garant, emploi), créée
 * automatiquement à la création de l'employé, avec aperçu et PDF. Contenu personnel : droits dédiés par action
 * `hiring_reports.view/create/update/delete` (voir `HiringReportAccess`), rien de public, rien dans le journal.
 */
class HiringReportServiceProvider extends BundleServiceProvider
{
    public const SLUG = 'hiring-report';

    public function slug(): string
    {
        return self::SLUG;
    }

    public function name(): string
    {
        return 'Hiring reports';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function description(): string
    {
        return 'Hiring report of each employee per store, created when the employee is created, with preview and PDF.';
    }

    public function requiredContract(): string
    {
        return '^1.4';
    }

    protected function bootBundle(): void
    {
        if ($this->isEnabled()) {
            Livewire::component('hiring-report.index', Index::class);
            Livewire::component('hiring-report.form', Form::class);
            Livewire::component('hiring-report.show', Show::class);
        }

        // Les gérants gèrent les rapports d'embauche de leur magasin ; un employé n'a aucun droit par défaut.
        $this->permissions('hiring_reports', ['view', 'create', 'update', 'delete'], [
            'manager' => ['view', 'create', 'update', 'delete'],
        ]);

        $this->navigation(new NavigationItem(
            key: 'hiring-report', label: 'Hiring reports', route: 'hiring-report.index', icon: 'user-plus',
            section: NavigationRegistry::SECTION_REPORTS, order: 60,
            visible: fn (Authenticatable $user): bool => $user instanceof User && $this->app->make(HiringReportAccess::class)->storesWith($user, 'hiring_reports.view') !== [],
            current: ['hiring-report.index', 'hiring-report.show', 'hiring-report.create', 'hiring-report.edit'],
        ));

        foreach ([
            'hiring_report.created' => 'Hiring report created',
            'hiring_report.updated' => 'Hiring report updated',
            'hiring_report.deleted' => 'Hiring report deleted',
            'hiring_report.viewed' => 'Hiring report viewed',
        ] as $key => $label) {
            $this->activityAction($key, $label, 'Hiring reports');
        }

        $this->listen(EmployeeCreated::class, [HiringReportOnEmployeeCreated::class, 'handle']);

        // Tout le contenu d'un rapport est personnel : celui d'un employé anonymisé est supprimé.
        $this->listen(UserAnonymized::class, fn (UserAnonymized $event) => HiringReport::query()->where('user_id', $event->userId)->delete());
    }
}
