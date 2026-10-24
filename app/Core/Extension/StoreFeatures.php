<?php

declare(strict_types=1);

namespace kintai\Core\Extension;

use kintai\Contract\Extension\StoreFeature;
use kintai\Core\Container;

/**
 * Fonctionnalités activables par magasin (fiche magasin, table store_features), construites à partir de données :
 * - celles du Core (planning) ;
 * - celles déclarées par les bundles actifs (point d'extension Bundle::storeFeatures(), contrat 1.3).
 */
final class StoreFeatures
{
    public function __construct(private readonly ?ExtensionRegistry $registry = null) {}

    /** Instance de la requête courante (registre des extensions), ou Core seul si indisponible. */
    public static function current(): self
    {
        try {
            $container = Container::getInstance();
            return new self($container->has(ExtensionRegistry::class) ? $container->make(ExtensionRegistry::class) : null);
        } catch (\Throwable) {
            return new self();
        }
    }

    /** @return list<StoreFeature> */
    public static function coreFeatures(): array
    {
        return [new StoreFeature('shifts', 'feature_shifts', 'feature_shifts_desc', '📅', 10)];
    }

    /**
     * Fonctionnalités proposées sur la fiche magasin : Core, puis bundles actifs déclarants ; triées par ordre. Une clé
     * déjà présente n'est pas reprise.
     *
     * @return list<StoreFeature>
     */
    public function available(): array
    {
        $features = self::coreFeatures();
        array_push($features, ...($this->registry?->storeFeatures() ?? []));

        $unique = [];
        foreach ($features as $feature) {
            $unique[$feature->key] ??= $feature;
        }
        $unique = array_values($unique);
        usort($unique, static fn(StoreFeature $a, StoreFeature $b): int => [$a->order, $a->key] <=> [$b->order, $b->key]);
        return $unique;
    }

    /** @return list<string> */
    public function availableKeys(): array
    {
        return array_map(static fn(StoreFeature $f): string => $f->key, $this->available());
    }

    /**
     * Une fonctionnalité de bundle est-elle utilisable sur cette instance ? Toujours vrai : les fonctionnalités
     * proposées viennent des bundles actifs, et le Core ne connaît celles d'aucun bundle inactif (depuis l'extraction
     * de tous les bundles, il n'en garde plus de liste). Conservée pour feat_bundle() ; à combiner avec l'activation
     * par magasin.
     */
    public function isAvailable(string $key): bool
    {
        return true;
    }
}
