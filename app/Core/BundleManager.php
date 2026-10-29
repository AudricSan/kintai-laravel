<?php

declare(strict_types=1);

namespace kintai\Core;

use Illuminate\Foundation\Application;

final class BundleManager
{
    private Application $app;
    /** @var Bundle[] */
    private array $bundles = [];

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /** @var list<string> fichiers de routes de bundles en attente des routes du Core */
    private array $queuedRoutes = [];
    private bool $routesReady = false;

    /** Un bundle demande le chargement de son fichier de routes (Bundle::loadRoutesFrom()). */
    public function queueRoutes(string $path): void
    {
        if ($this->routesReady) {
            $this->requireRoutes($path);
            return;
        }
        $this->queuedRoutes[] = $path;
    }

    /** Les routes du Core sont déclarées : celles des bundles viennent ensuite, et toute demande suivante est immédiate. */
    public function loadQueuedRoutes(): void
    {
        $this->routesReady = true;
        foreach ($this->queuedRoutes as $path) {
            $this->requireRoutes($path);
        }
        $this->queuedRoutes = [];
    }

    private function requireRoutes(string $path): void
    {
        if (!($this->app instanceof \Illuminate\Contracts\Foundation\CachesRoutes && $this->app->routesAreCached())) {
            require $path;
        }
    }

    public function registerBundle(string $bundleClass): void
    {
        if (isset($this->bundles[$bundleClass])) {
            return;
        }

        // Un bundle est un service provider Laravel : l'application l'enregistre, puis le démarre (routes, vues…).
        $bundle = $this->app->register(new $bundleClass($this->app));
        $bundle->registerMigrations();
        $bundle->registerTranslations();
        $this->bundles[$bundleClass] = $bundle;
    }

    /**
     * @return Bundle[]
     */
    public function getBundles(): array
    {
        return $this->bundles;
    }

    /**
     * Un bundle est réellement actif (routes chargées, services enregistrés) s'il a
     * été à la fois découvert sur le disque ET activé via FeatureManager — voir
     * BundleServiceProvider::register(). Un slug activé par FeatureManager mais
     * absent du disque (bundle désinstallé, ou bundle pilote pas encore installé
     * depuis /admin/bundles/market) n'est PAS actif : contrairement à
     * FeatureManager::isEnabled(), qui ne reflète que le réglage stocké, ceci
     * reflète l'état réel après boot. À utiliser partout où du code suppose
     * qu'une route/vue du bundle existe réellement.
     */
    public function isActive(string $slug): bool
    {
        foreach ($this->bundles as $bundle) {
            if ($bundle->getName() === $slug) {
                return true;
            }
        }
        return false;
    }

    /**
     * Dossier d'assets statiques déclaré par le bundle actif $slug via
     * loadAssetsFrom(), ou null si le bundle est inactif ou n'a jamais
     * appelé loadAssetsFrom(). Utilisé par BundleAssetController et par
     * les helpers bundle_asset()/bundle_asset_path().
     */
    public function assetsPathFor(string $slug): ?string
    {
        foreach ($this->bundles as $bundle) {
            if ($bundle->getName() === $slug) {
                return $bundle->getAssetsPath();
            }
        }
        return null;
    }

    /** Version du bundle actif $slug (pour le cache-busting de ses assets), ou null si inactif. */
    public function versionOf(string $slug): ?string
    {
        foreach ($this->bundles as $bundle) {
            if ($bundle->getName() === $slug) {
                return $bundle->getVersion();
            }
        }
        return null;
    }
}
