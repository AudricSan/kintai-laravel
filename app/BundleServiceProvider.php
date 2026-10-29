<?php

declare(strict_types=1);

namespace App;

use Illuminate\Support\ServiceProvider;

use App\Services\Log;

final class BundleServiceProvider extends ServiceProvider
{
    /** Au démarrage, une fois tous les fournisseurs enregistrés : la découverte lit les libellés des bundles (__()). */
    public function boot(): void
    {
        $bundleManager = $this->app->make(BundleManager::class);
        $featureManager = $this->app->make(FeatureManager::class);
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
