<?php

declare(strict_types=1);

namespace kintai\Core;

final class BundleManager
{
    private Application $app;
    /** @var Bundle[] */
    private array $bundles = [];

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function registerBundle(string $bundleClass): void
    {
        if (isset($this->bundles[$bundleClass])) {
            return;
        }

        $bundle = new $bundleClass($this->app);
        $bundle->register();
        $this->bundles[$bundleClass] = $bundle;
    }

    public function boot(): void
    {
        foreach ($this->bundles as $bundle) {
            $bundle->boot();
        }
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
