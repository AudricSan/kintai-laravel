<?php

declare(strict_types=1);

namespace kintai\Core;

use kintai\Core\BundleContract\BundleManifest;

/**
 * Autoload dédié aux bundles installés dynamiquement (storage/bundles/),
 * hors du PSR-4 standard de composer.json puisqu'ils vivent hors de src/.
 * Enregistré une seule fois, tôt dans le bootstrap (public/index.php, juste
 * après vendor/autoload.php) — voir docs/architecture.md "Modular Bundles".
 *
 * Le namespace racine kintai\Bundles\Installed\{Nom}\ (déclaré par chaque
 * bundle.json, pas par convention de nom de dossier) le distingue du
 * mécanisme legacy kintai\Bundles\{Nom}\, pour qu'il n'y ait jamais
 * d'ambiguïté de résolution entre les deux.
 */
final class InstalledBundleAutoloader
{
    /** @var array<string, BundleManifest> bundleRootDir => manifeste */
    private array $manifestsByRoot = [];

    public function __construct(
        private readonly InstalledBundleManifestStore $store = new InstalledBundleManifestStore(),
        private readonly ?string $bundlesDir = null,
    ) {
    }

    public function register(): void
    {
        $bundlesDir = $this->bundlesDir ?? storage_path('bundles');

        foreach ($this->store->all() as $slug => $info) {
            $version = $info['active_version'] ?? null;
            if (!is_string($version) || $version === '') {
                continue;
            }

            $bundleRoot = $bundlesDir . '/' . $slug . '/' . $version;
            $manifestPath = $bundleRoot . '/bundle.json';
            if (!is_file($manifestPath)) {
                continue;
            }

            $data = json_decode((string) file_get_contents($manifestPath), true);
            $manifest = BundleManifest::fromArray($data);
            if ($manifest !== null) {
                $this->manifestsByRoot[$bundleRoot] = $manifest;
            }
        }

        spl_autoload_register(function (string $class): void {
            foreach ($this->manifestsByRoot as $bundleRoot => $manifest) {
                $file = $manifest->classFilePath($bundleRoot, $class);
                if ($file !== null && is_file($file)) {
                    require $file;
                    return;
                }
            }
        });
    }
}
