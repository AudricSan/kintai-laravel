<?php

namespace Tests\Concerns;

use App\Bundles\BundleManager;
use App\Settings\AppSettings;
use LogicException;
use Tests\Fixtures\Bundles\DemoBundle\DemoBundleServiceProvider;
use Tests\Fixtures\Bundles\FutureBundleServiceProvider;

/**
 * Branche les bundles de test dans l'application. Un bundle se branche au démarrage : on le (ré)enregistre ici après
 * avoir posé l'état d'activation, comme le ferait une requête qui démarre avec ce réglage.
 */
trait InstallsBundles
{
    /**
     * @param  list<string>  $enabled  Slugs activés par l'Owner.
     */
    protected function installBundles(array $enabled, bool $withFuture = false): void
    {
        app(AppSettings::class)->set(BundleManager::SETTING, (string) json_encode($enabled));

        $this->app->register(DemoBundleServiceProvider::class, true);

        if ($withFuture) {
            $this->app->register(FutureBundleServiceProvider::class, true);
        }
    }

    /**
     * Pour les tests d'un bundle installé par Composer (`packages/bundles/<slug>/tests`) : l'active puis rejoue le
     * démarrage de son service provider, comme le ferait la requête suivante de l'Owner.
     */
    protected function enableBundle(string $slug): void
    {
        $manager = app(BundleManager::class);
        $provider = $manager->find($slug) ?? throw new LogicException("The bundle [{$slug}] is not installed.");

        $manager->enable($slug);
        $this->app->register($provider::class, true);
    }
}
