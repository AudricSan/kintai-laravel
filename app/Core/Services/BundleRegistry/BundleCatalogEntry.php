<?php

declare(strict_types=1);

namespace kintai\Core\Services\BundleRegistry;

/**
 * Une entrée de catalogue agrégé : un bundle disponible, avec le registry
 * dont il provient (deux registries peuvent lister le même slug — aucune
 * déduplication automatique, l'Owner choisit sa source à l'installation).
 */
final readonly class BundleCatalogEntry
{
    public function __construct(
        public BundleRegistryEntry $bundle,
        public string $registryName,
        public string $registryUrl,
    ) {
    }
}
