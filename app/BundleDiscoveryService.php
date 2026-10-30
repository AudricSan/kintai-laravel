<?php

declare(strict_types=1);

namespace App;

use App\Bundles\Bundle as ContractBundle;
use App\Bundles\BundleManifest;
use Illuminate\Support\Facades\Log;

/**
 * Découvre les bundles disponibles depuis deux sources, fusionnées en une
 * seule liste :
 *  - le monorepo legacy (app/Bundles/*, convention de nom de classe) ;
 *  - les bundles installés dynamiquement (storage/bundles/, un par slug
 *    listé dans installed.json, chacun avec son propre bundle.json).
 *
 * Un bundle tiers déposé manuellement dans app/Bundles/ (namespace
 * `kintai\Bundles\{Nom}\{Nom}Bundle`, PSR-4) est détecté au même titre qu'un
 * bundle du dépôt d'origine ; comme un bundle installé, il n'est chargé que si
 * son bundle.json déclare un contrat (« kintai_contract ») que ce Core fournit. Voir config/official-bundles.php pour la
 * distinction officiel / tiers, faite ailleurs (pas ici) : ce service se
 * contente de lister ce qui existe.
 *
 * Les instances créées ici servent uniquement à lire des métadonnées
 * (getName/getLabel/getDescription/getVersion) : on les construit
 * volontairement sans passer par Bundle::__construct() (donc sans
 * Application ni accès container) pour que la découverte reste bon marché et
 * sans effet de bord, même pour un bundle tiers mal écrit. L'instanciation
 * "réelle" avec Application a lieu séparément, dans
 * BundleManager::registerBundle(), uniquement pour un bundle effectivement
 * activé.
 */
final class BundleDiscoveryService
{
    /**
     * Bundles supprimés du projet : une instance qui les avait installés ne doit plus les charger (leur code
     * dépendrait de classes du Core qui n'existent plus).
     */
    public const RETIRED_SLUGS = ['feedback'];

    private readonly string $bundlesDir;
    private readonly InstalledBundleManifestStore $installedStore;
    private readonly string $installedBundlesDir;

    public function __construct(
        ?string $bundlesDir = null,
        ?InstalledBundleManifestStore $installedStore = null,
        ?string $installedBundlesDir = null,
        // Version du Core courante (config/app.php) ; injectable pour les tests.
        private readonly ?string $coreVersion = null,
    ) {
        $this->bundlesDir = $bundlesDir ?? BASE_PATH . '/app/Bundles';
        $this->installedStore = $installedStore ?? new InstalledBundleManifestStore();
        $this->installedBundlesDir = $installedBundlesDir ?? storage_path('bundles');
    }

    /**
     * @return array<string, array{class: class-string<ContractBundle>, label: string, description: string, version: string}>
     *         Clé = slug retourné par Bundle::getName().
     */
    public function discover(): array
    {
        $discovered = $this->discoverLegacy();

        foreach ($this->discoverInstalled() as $slug => $meta) {
            if (isset($discovered[$slug])) {
                // Ne devrait jamais arriver en usage normal (un slug legacy migré
                // vers l'installation dynamique doit d'abord être retiré de
                // app/Bundles/) : le legacy gagne plutôt que de risquer de charger
                // deux implémentations différentes du même slug.
                Log::warning('bundle_discovery_slug_collision', ['slug' => $slug]);
                continue;
            }
            $discovered[$slug] = $meta;
        }

        return $discovered;
    }

    /**
     * @return array<string, array{class: class-string<ContractBundle>, label: string, description: string, version: string}>
     */
    private function discoverLegacy(): array
    {
        $discovered = [];

        if (!is_dir($this->bundlesDir)) {
            return $discovered;
        }

        $entries = scandir($this->bundlesDir);
        if ($entries === false) {
            return $discovered;
        }

        foreach ($entries as $dirName) {
            if ($dirName === '.' || $dirName === '..') {
                continue;
            }
            if (!is_dir($this->bundlesDir . '/' . $dirName)) {
                continue;
            }

            $class = "kintai\\Bundles\\{$dirName}\\{$dirName}Bundle";
            if (!class_exists($class) || !is_subclass_of($class, ContractBundle::class)) {
                continue;
            }

            try {
                /** @var ContractBundle $instance */
                $instance = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
                $manifest = $this->readManifest($this->bundlesDir . '/' . $dirName . '/bundle.json');
                $discovered[$instance->getName()] = [
                    'class'       => $class,
                    'label'       => $instance->getLabel(),
                    'description' => $instance->getDescription(),
                    'version'     => $instance->getVersion(),
                    // Même règle qu'un bundle installé : sans contrat déclaré et fourni, listé mais jamais chargé.
                    'incompatible' => $manifest === null ? __('bundle_contract_missing') : $manifest->incompatibilityReason($this->coreVersion()),
                ];
            } catch (\Throwable) {
                // Un bundle tiers mal formé ne doit jamais faire planter le boot ou l'admin.
                continue;
            }
        }

        return $discovered;
    }

    /**
     * @return array<string, array{class: class-string<ContractBundle>, label: string, description: string, version: string}>
     */
    private function discoverInstalled(): array
    {
        $discovered = [];

        foreach ($this->installedStore->all() as $slug => $info) {
            if (in_array($slug, self::RETIRED_SLUGS, true)) {
                continue;
            }
            $version = $info['active_version'] ?? null;
            if (!is_string($version) || $version === '') {
                continue;
            }

            $bundleRoot = $this->installedBundlesDir . '/' . $slug . '/' . $version;
            $manifestPath = $bundleRoot . '/bundle.json';
            if (!is_file($manifestPath)) {
                continue;
            }

            $manifest = $this->readManifest($manifestPath);
            if ($manifest === null || $manifest->slug !== $slug) {
                continue;
            }

            if (!class_exists($manifest->entryClass) || !is_subclass_of($manifest->entryClass, ContractBundle::class)) {
                continue;
            }

            try {
                /** @var ContractBundle $instance */
                $instance = (new \ReflectionClass($manifest->entryClass))->newInstanceWithoutConstructor();
                $discovered[$slug] = [
                    'class'       => $manifest->entryClass,
                    'label'       => $instance->getLabel(),
                    'description' => $instance->getDescription(),
                    // Version du dossier actif, pas celle du manifeste : bundle.json
                    // ne porte que la ligne de base "X.Y.0", Z est calculé par le
                    // workflow de release du bundle.
                    'version'     => $version,
                    // Bundle devenu incompatible (mise à jour du Core, contrat relevé) : listé pour que l'Owner le
                    // voie, jamais chargé (voir BundleServiceProvider). null = compatible.
                    'incompatible' => $manifest->incompatibilityReason($this->coreVersion()),
                ];
            } catch (\Throwable) {
                continue;
            }
        }

        return $discovered;
    }

    /**
     * Bundles installés (version active) qui ne tourneraient pas sur un Core fournissant ce contrat : avertissement
     * de /admin/update avant d'appliquer une mise à jour du Core.
     *
     * @return list<array{slug: string, name: string, version: string, reason: string}>
     */
    public function installedIncompatibleWith(string $contractVersion): array
    {
        $incompatible = [];
        foreach ($this->installedStore->all() as $slug => $info) {
            $version = $info['active_version'] ?? null;
            if (in_array($slug, self::RETIRED_SLUGS, true) || !is_string($version) || $version === '') {
                continue;
            }
            $manifest = $this->readManifest($this->installedBundlesDir . '/' . $slug . '/' . $version . '/bundle.json');
            $reason = $manifest === null
                ? __('bundle_contract_missing')
                : $manifest->incompatibilityReason($this->coreVersion(), $contractVersion);
            if ($reason !== null) {
                $incompatible[] = ['slug' => $slug, 'name' => $manifest->name ?? $slug, 'version' => $version, 'reason' => $reason];
            }
        }
        return $incompatible;
    }

    private function readManifest(string $path): ?BundleManifest
    {
        return is_file($path) ? BundleManifest::fromArray(json_decode((string) file_get_contents($path), true)) : null;
    }

    private function coreVersion(): string
    {
        if ($this->coreVersion !== null) {
            return $this->coreVersion;
        }
        $config = defined('BASE_PATH') && is_file(BASE_PATH . '/config/app.php') ? require BASE_PATH . '/config/app.php' : [];
        return is_array($config) && is_string($config['version'] ?? null) ? $config['version'] : '0.0.0';
    }
}
