<?php

namespace Kintai\Bundles\PlanningImport;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Kintai\Bundles\PlanningImport\Livewire\Index;
use Kintai\Bundles\PlanningImport\Livewire\Settings;
use Kintai\Bundles\PlanningImport\Models\ImportAlias;
use Kintai\Contracts\Bundle\BundleServiceProvider;
use Kintai\Contracts\Events\UserAnonymized;
use Kintai\Contracts\Navigation\NavigationItem;
use Kintai\Contracts\Navigation\NavigationRegistry;
use Livewire\Livewire;

/**
 * Import de planning Excel : envoi du fichier, aperçu (détection en trois phases, rattachement des noms aux employés),
 * confirmation qui crée les shifts avec les actions du Core. Droit : `shifts.import` du Core, avec sa portée magasin.
 * Réglages par magasin : grille du fichier et pause automatique.
 */
class PlanningImportServiceProvider extends BundleServiceProvider
{
    public const SLUG = 'planning-import';

    public function slug(): string
    {
        return self::SLUG;
    }

    public function name(): string
    {
        return 'Schedule import';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function description(): string
    {
        return 'Import an Excel schedule: upload, preview, confirm; staff names are remembered per store.';
    }

    public function requiredContract(): string
    {
        return '^1.5';
    }

    protected function bootBundle(): void
    {
        if ($this->isEnabled()) {
            Livewire::component('planning-import.index', Index::class);
            Livewire::component('planning-import.settings', Settings::class);
        }

        $access = fn (): ImportAccess => $this->app->make(ImportAccess::class);

        $this->navigation(new NavigationItem(
            key: 'planning-import', label: 'Schedule import', route: 'planning-import.index', icon: 'arrow-up-tray',
            section: NavigationRegistry::SECTION_PLANNING, order: 60,
            visible: fn (Authenticatable $user): bool => $user instanceof User && $access()->stores($user) !== [],
            current: ['planning-import.index', 'planning-import.settings'],
        ));

        foreach ([
            'planning_import.imported' => 'Schedule imported',
            'planning_import.settings_updated' => 'Schedule import settings updated',
        ] as $key => $label) {
            $this->activityAction($key, $label, 'Schedule import');
        }

        // Un alias porte le nom d'un employé tel qu'il est écrit dans les fichiers : il part avec l'anonymisation.
        $this->listen(UserAnonymized::class, fn (UserAnonymized $event) => ImportAlias::query()->where('user_id', $event->userId)->delete());
    }
}
