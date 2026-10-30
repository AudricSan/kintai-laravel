<?php

declare(strict_types=1);

namespace kintai\Bundles\Installed\StorePhoto;

use Illuminate\Console\Scheduling\Schedule;
use kintai\Bundles\Installed\StorePhoto\Console\RetentionCommand;
use kintai\Bundles\Installed\StorePhoto\Repositories\DatabaseStorePhotoRepository;
use kintai\Bundles\Installed\StorePhoto\Repositories\StorePhotoRepositoryInterface;
use kintai\Contract\Bundle;
use kintai\Contract\Extension\NavItem;
use kintai\Contract\Extension\StoreFeature;

/**
 * Rapports photo des magasins. Le bundle possède ses modèles, son dépôt et sa migration (tables store_photo_*), ainsi
 * que la rétention des photos (tâche planifiée « photo-retention ») : le Core n'en connaît plus rien depuis
 * l'extraction (étape 4 du plan du contrat). Désactiver le bundle conserve les envois en base et sur le disque.
 */
final class StorePhotoBundle extends Bundle
{
    public function getName(): string
    {
        return 'store-photos';
    }


    public function getLabel(): string
    {
        return __('bundle_store_photos');
    }

    public function getDescription(): string
    {
        return __('bundle_store_photos_desc');
    }

    public function register(): void
    {
        // Tâche planifiée : commande Artisan du bundle, lancée par le planificateur de Laravel.
        $this->commands([RetentionCommand::class]);
        $this->callAfterResolving(Schedule::class, static fn(Schedule $schedule) => $schedule->command('kintai:photo-retention')->dailyAt('02:00')->withoutOverlapping());
        $this->app->singleton(StorePhotoRepositoryInterface::class, fn() => new DatabaseStorePhotoRepository());
        $this->loadViewsFrom($this->getPath() . '/Views', 'store-photos');
        $this->loadRoutesFrom($this->getPath() . '/routes.php');
        $this->loadAssetsFrom('public');
    }

    /**
     * Navigation (contrat 1.1) : entrée(s) de la barre du haut et de la barre mobile.
     */
    public function navigation(): array
    {
        return [
            new NavItem('photos', NavItem::REPORTS, 'store_photos_photos', 'admin.photos.index', 'admin.photos.index',
                icon: 'camera', activePaths: ['/admin/photos'], storeFeature: 'photos', order: 60),
        ];
    }

    /**
     * Catégories de permissions (contrat 1.2), proposées sur la page des rôles.
     */
    public function permissions(): array
    {
        return ['photos' => ['view', 'create', 'update', 'delete']];
    }

    /**
     * Fonctionnalité activable par magasin (contrat 1.3), sur la fiche magasin.
     */
    public function storeFeatures(): array
    {
        return [new StoreFeature('photos', 'store_photos_feature_photos', 'store_photos_feature_photos_desc', '📷', 80)];
    }


    /**
     * Type de notification : envoi de photos archivé, prévenu aux Owners avant suppression définitive. Format enrichi
     * (contrat 1.23) : catégorie « reports », push par défaut, pas d'e-mail ; l'identifiant passé à notify() est celui de l'envoi.
     */
    public function notificationTypes(): array
    {
        return [
            'photo_archived' => ['icon' => '📦', 'category' => 'reports', 'channels' => ['push' => true, 'email' => false], 'reference_type' => 'photo_submission'],
        ];
    }
}
