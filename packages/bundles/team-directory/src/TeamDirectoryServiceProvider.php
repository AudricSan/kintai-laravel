<?php

namespace Kintai\Bundles\TeamDirectory;

use Illuminate\Contracts\Auth\Authenticatable;
use Kintai\Bundles\TeamDirectory\Livewire\EditProfile;
use Kintai\Bundles\TeamDirectory\Livewire\Index;
use Kintai\Bundles\TeamDirectory\Livewire\Show;
use Kintai\Bundles\TeamDirectory\Models\TeamProfile;
use Kintai\Contracts\Bundle\BundleServiceProvider;
use Kintai\Contracts\Events\UserAnonymized;
use Kintai\Contracts\Navigation\NavigationItem;
use Kintai\Contracts\Navigation\NavigationRegistry;
use Kintai\Contracts\Stores\ColleagueDirectory;
use Livewire\Livewire;

/**
 * Annuaire des collègues : la liste des collègues des magasins que l'on partage (l'Owner voit toute l'organisation), la
 * fiche de chacun (profil d'équipe et coordonnées partagées) et la page où chacun écrit son propre profil d'équipe.
 *
 * Libre-service, comme le profil : pas de permission à attribuer. La visibilité suit les règles du Core
 * (`ColleagueDirectory`) et la fonctionnalité de magasin « team-directory » (active par défaut).
 */
class TeamDirectoryServiceProvider extends BundleServiceProvider
{
    public const SLUG = 'team-directory';

    public const FEATURE = 'team-directory';

    public function slug(): string
    {
        return self::SLUG;
    }

    public function name(): string
    {
        return 'Team directory';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function description(): string
    {
        return 'Colleague directory of your stores, with a team profile (bio, skills, languages spoken, hobbies).';
    }

    protected function bootBundle(): void
    {
        if ($this->isEnabled()) {
            Livewire::component('team-directory.index', Index::class);
            Livewire::component('team-directory.show', Show::class);
            Livewire::component('team-directory.edit-profile', EditProfile::class);
        }

        $this->storeFeature(self::FEATURE, 'Team directory');
        $this->activityAction('team-directory.profile_updated', 'Team profile updated', 'Team directory');

        $hasStore = fn (Authenticatable $user): bool => $this->app->make(ColleagueDirectory::class)->stores($user, self::FEATURE) !== [];

        $this->navigation(new NavigationItem(
            key: 'team-directory', label: 'Team', route: 'team-directory.index', icon: 'users',
            section: NavigationRegistry::SECTION_MAIN, order: 25, visible: $hasStore, current: ['team-directory.index', 'team-directory.show'],
        ));
        $this->profileSection('team-directory', 'Team profile', 'team-directory.profile', 18, $hasStore);

        // Le profil d'équipe est une donnée personnelle : il disparaît avec l'anonymisation du compte.
        $this->listen(UserAnonymized::class, fn (UserAnonymized $event) => TeamProfile::query()->where('user_id', $event->userId)->delete());
    }
}
