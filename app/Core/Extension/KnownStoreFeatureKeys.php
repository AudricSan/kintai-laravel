<?php

declare(strict_types=1);

namespace kintai\Core\Extension;

use kintai\Core\BundleDiscoveryService;
use kintai\Core\Container;
use kintai\Core\InstalledBundleAutoloader;

/**
 * Clés de toutes les fonctionnalités par magasin connues de l'instance : celles du Core et celles déclarées par chaque
 * bundle installé, actif ou non (Bundle::storeFeatures(), classe d'entrée instanciée sans constructeur comme à la
 * découverte). Sert à la conversion des magasins configurés avant l'enregistrement des décisions par case : une clé
 * absente de leur liste valait « désactivée ».
 */
final class KnownStoreFeatureKeys
{
    /** @return list<string> */
    public static function all(): array
    {
        $keys = array_map(static fn($f): string => $f->key, StoreFeatures::coreFeatures());
        try {
            // Classes des bundles installés chargeables hors du front controller (migrations en ligne de commande).
            (new InstalledBundleAutoloader())->register();
            $discovery = Container::getInstance()->make(BundleDiscoveryService::class);
            foreach ($discovery->discover() as $meta) {
                try {
                    $bundle = (new \ReflectionClass($meta['class']))->newInstanceWithoutConstructor();
                    foreach ((array) $bundle->storeFeatures() as $feature) {
                        if (is_object($feature) && isset($feature->key) && is_string($feature->key)) {
                            $keys[] = $feature->key;
                        }
                    }
                } catch (\Throwable) {
                    // Bundle illisible : ses fonctionnalités restent inconnues (actives par défaut).
                }
            }
        } catch (\Throwable) {
            // Application non démarrée (tests) : fonctionnalités du Core seulement.
        }
        return array_values(array_unique($keys));
    }
}
