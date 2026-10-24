<?php

declare(strict_types=1);

namespace kintai\Core\Services\BundleRegistry;

/**
 * Contenu parsé et validé d'un fichier registry.json. Un registry plus
 * récent que ce que cette version de Kintai sait lire (schema_version
 * supérieur) est rejeté proprement par fromArray() plutôt que mal interprété.
 *
 * Schema 1 (historique) : `versions` par bundle est une liste plate, sans
 * notion de canal. Schema 2 : `versions` devient un objet {release, beta,
 * alpha} — voir BundleRegistryEntry pour le détail de la normalisation. Les
 * deux restent acceptés : un registry tiers qui n'a pas encore adopté le
 * schema 2 continue de fonctionner (toujours le même jeu de versions, quel
 * que soit le canal choisi côté instance).
 */
final readonly class BundleRegistryListing
{
    public const SUPPORTED_SCHEMA_VERSIONS = [1, 2];

    /**
     * @param BundleRegistryEntry[] $bundles
     */
    public function __construct(
        public string $name,
        public array $bundles,
    ) {
    }

    public static function fromArray(mixed $data): ?self
    {
        if (!is_array($data)) {
            return null;
        }
        if (!in_array($data['schema_version'] ?? null, self::SUPPORTED_SCHEMA_VERSIONS, true)) {
            return null;
        }
        if (!is_array($data['bundles'] ?? null)) {
            return null;
        }

        $bundles = [];
        foreach ($data['bundles'] as $entry) {
            $parsed = BundleRegistryEntry::fromArray($entry);
            if ($parsed !== null) {
                $bundles[] = $parsed;
            }
        }

        return new self(
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            bundles: $bundles,
        );
    }
}
