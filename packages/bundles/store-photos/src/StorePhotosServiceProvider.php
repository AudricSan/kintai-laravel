<?php

namespace Kintai\Bundles\StorePhotos;

use Illuminate\Console\Scheduling\Schedule;
use Kintai\Bundles\StorePhotos\Console\RetentionCommand;
use Kintai\Bundles\StorePhotos\Livewire\Create;
use Kintai\Bundles\StorePhotos\Livewire\Index;
use Kintai\Bundles\StorePhotos\Livewire\Settings;
use Kintai\Bundles\StorePhotos\Livewire\Show;
use Kintai\Contracts\Bundle\BundleServiceProvider;
use Kintai\Contracts\Navigation\NavigationItem;
use Kintai\Contracts\Navigation\NavigationRegistry;
use Kintai\Contracts\Notifications\NotificationType;
use Kintai\Contracts\Settings\SettingDefinition;
use Livewire\Livewire;

/**
 * Photos de magasin : envoi sécurisé (types vérifiés par le contenu, ré-encodage, nom généré), stockage privé, lecture par
 * une route contrôlée, rétention. Droits `photos.view/create/update/delete` (voir `PhotoAccess`), fonctionnalité de
 * magasin `photos`, fichiers inclus dans les sauvegardes du Core (`backupFiles()`).
 */
class StorePhotosServiceProvider extends BundleServiceProvider
{
    public const SLUG = 'store-photos';

    public const FEATURE = 'photos';

    public const NOTIFICATION = 'photo_archived';

    public function slug(): string
    {
        return self::SLUG;
    }

    public function name(): string
    {
        return 'Store photos';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function description(): string
    {
        return 'Store photo reports: secure upload, private storage, retention.';
    }

    public function requiredContract(): string
    {
        return '^1.4';
    }

    protected function bootBundle(): void
    {
        // Les photos sont sauvegardées même bundle désactivé (désactiver ne supprime aucune donnée).
        $this->backupFiles(PhotoStorage::DIRECTORY);
        $this->commands([RetentionCommand::class]);

        if ($this->isEnabled()) {
            Livewire::component('store-photos.index', Index::class);
            Livewire::component('store-photos.create', Create::class);
            Livewire::component('store-photos.show', Show::class);
            Livewire::component('store-photos.settings', Settings::class);

            $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $schedule->command('kintai:photo-retention')->dailyAt('02:00')->name('kintai:photo-retention')->withoutOverlapping());
        }

        // Les gérants consultent, envoient et tournent les photos de leurs magasins ; la suppression reste à l'Owner.
        $this->permissions('photos', ['view', 'create', 'update', 'delete'], ['manager' => ['view', 'create', 'update']]);
        $this->storeFeature(self::FEATURE, 'Store photos');
        $this->settings(
            new SettingDefinition('photos.retention_days', SettingDefinition::TYPE_INT, 14, 1, 365),
            new SettingDefinition('photos.cleanup_delay', SettingDefinition::TYPE_INT, 7, 1, 365),
        );
        $this->navigation(new NavigationItem(
            key: 'store-photos', label: 'Store photos', route: 'store-photos.index', icon: 'camera',
            section: NavigationRegistry::SECTION_REPORTS, order: 60, permission: 'photos.view', current: ['store-photos.index', 'store-photos.show', 'store-photos.create', 'store-photos.settings'],
        ));
        $this->notificationTypes(new NotificationType(self::NOTIFICATION, 'Photos past retention', 'reports', 'camera', push: true, referenceType: 'photo_submission'));
        foreach ([
            'photo.submission_created' => 'Photos sent',
            'photo.submission_merged' => 'Photos added to the day report',
            'photo.submission_deleted' => 'Photo report deleted',
            'photo.image_rotated' => 'Photo rotated',
            'photo.settings_updated' => 'Photo retention settings updated',
        ] as $key => $label) {
            $this->activityAction($key, $label, 'Store photos');
        }
    }
}
