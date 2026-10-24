<?php

declare(strict_types=1);

namespace kintai\Contract\Extension;

/**
 * Fonctionnalité activable magasin par magasin, fournie par un bundle (point d'extension `Bundle::storeFeatures()`,
 * contrat 1.3).
 *
 * Le Core la propose sur la fiche magasin (carte « Fonctionnalités ») tant que le bundle est actif, et l'enregistre
 * dans store_features sous la clé `key`. Le bundle teste ensuite la clé côté employé (HasStoreFeatureCheck) et peut
 * la donner en `storeFeature` à ses entrées de navigation pour qu'elles disparaissent quand elle est désactivée.
 */
final class StoreFeature
{
    /**
     * @param string $key         Identifiant unique enregistré par magasin, ex. « timeoff »
     * @param string $label       Clé de traduction du titre de la carte
     * @param string $description Clé de traduction de la description
     * @param string $icon        Icône de la carte (un emoji)
     * @param int    $order       Ordre sur la fiche magasin (celles du Core vont de 10 en 10)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $description = '',
        public readonly string $icon = '',
        public readonly int $order = 100,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{1,40}$/', $key) !== 1) {
            throw new \InvalidArgumentException("StoreFeature : clé invalide « {$key} » (minuscules, chiffres, _).");
        }
    }
}
