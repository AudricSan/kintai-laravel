<?php

namespace Kintai\Contracts\Bundle;

/**
 * Savoir si un bundle est activé : implémenté par le Core (gestion des bundles de l'Owner) et lié dans le conteneur.
 * Un bundle n'y touche pas directement, `BundleServiceProvider` s'en sert. Hors du Core (aucune liaison), tout bundle
 * est considéré comme activé.
 */
interface BundleActivation
{
    /**
     * Le bundle est-il installé, activé par l'Owner et compatible avec la version du contrat ?
     */
    public function isEnabled(string $slug): bool;
}
