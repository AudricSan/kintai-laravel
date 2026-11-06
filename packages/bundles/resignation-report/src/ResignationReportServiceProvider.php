<?php

namespace Kintai\Bundles\ResignationReport;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Kintai\Bundles\ResignationReport\Livewire\Form;
use Kintai\Bundles\ResignationReport\Livewire\Index;
use Kintai\Bundles\ResignationReport\Livewire\Show;
use Kintai\Bundles\ResignationReport\Models\ResignationReport;
use Kintai\Contracts\Bundle\BundleServiceProvider;
use Kintai\Contracts\Events\UserAnonymized;
use Kintai\Contracts\Navigation\NavigationItem;
use Kintai\Contracts\Navigation\NavigationRegistry;
use Livewire\Livewire;

/**
 * Rapports de démission : le rapport de départ d'un employé dans un magasin (date, motif, préavis, remarques,
 * responsable), avec aperçu et PDF. Contenu personnel : droits dédiés par action
 * `resignation_reports.view/create/update/delete` (voir `ResignationReportAccess`), rien de public, rien dans le journal.
 */
class ResignationReportServiceProvider extends BundleServiceProvider
{
    public const SLUG = 'resignation-report';

    public function slug(): string
    {
        return self::SLUG;
    }

    public function name(): string
    {
        return 'Resignation reports';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function description(): string
    {
        return 'Resignation report of each employee per store, with preview and PDF.';
    }

    public function requiredContract(): string
    {
        return '^1.4';
    }

    protected function bootBundle(): void
    {
        if ($this->isEnabled()) {
            Livewire::component('resignation-report.index', Index::class);
            Livewire::component('resignation-report.form', Form::class);
            Livewire::component('resignation-report.show', Show::class);
        }

        // Les gérants gèrent les rapports de démission de leur magasin ; un employé n'a aucun droit par défaut.
        $this->permissions('resignation_reports', ['view', 'create', 'update', 'delete'], [
            'manager' => ['view', 'create', 'update', 'delete'],
        ]);

        $this->navigation(new NavigationItem(
            key: 'resignation-report', label: 'Resignation reports', route: 'resignation-report.index', icon: 'user-minus',
            section: NavigationRegistry::SECTION_REPORTS, order: 70,
            visible: fn (Authenticatable $user): bool => $user instanceof User && $this->app->make(ResignationReportAccess::class)->storesWith($user, 'resignation_reports.view') !== [],
            current: ['resignation-report.index', 'resignation-report.show', 'resignation-report.create', 'resignation-report.edit'],
        ));

        foreach ([
            'resignation_report.created' => 'Resignation report created',
            'resignation_report.updated' => 'Resignation report updated',
            'resignation_report.deleted' => 'Resignation report deleted',
            'resignation_report.viewed' => 'Resignation report viewed',
        ] as $key => $label) {
            $this->activityAction($key, $label, 'Resignation reports');
        }

        // Tout le contenu d'un rapport est personnel : celui d'un employé anonymisé est supprimé.
        $this->listen(UserAnonymized::class, fn (UserAnonymized $event) => ResignationReport::query()->where('user_id', $event->userId)->delete());
    }
}
