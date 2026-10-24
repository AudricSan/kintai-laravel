<?php

declare(strict_types=1);

namespace kintai\Core;

use kintai\Core\Services\Log;

final class BundleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $bundleManager = $this->container->make(BundleManager::class);
        $featureManager = $this->container->make(FeatureManager::class);
        $discovery = new BundleDiscoveryService();

        foreach ($discovery->discover() as $slug => $meta) {
            // Incompatible avec ce Core (contrat de bundle) : jamais chargé, même activé, pour ne pas faire tomber
            // l'application ; la page Bundles affiche la raison.
            if (($meta['incompatible'] ?? null) !== null) {
                Log::warning('bundle_incompatible_not_loaded', ['slug' => $slug, 'reason' => $meta['incompatible']]);
                continue;
            }
            if ($featureManager->isEnabled($slug)) {
                $bundleManager->registerBundle($meta['class']);
            }
        }
    }
}
