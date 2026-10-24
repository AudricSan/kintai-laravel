<?php

declare(strict_types=1);

namespace kintai\Contract\Provider;

/**
 * Fournisseur d'échanges de shifts (contrat 1.18), implémenté par un bundle (shift-swap) et déclaré par
 * `Bundle::swapProviders()`.
 *
 * Le Core l'interroge pour ses statistiques (nombre d'échanges d'un employé : colonne du rapport employés d'un magasin,
 * page des statistiques d'un employé) sans connaître les tables du bundle. Les échanges de tous les fournisseurs
 * déclarés sont réunis ; un fournisseur en échec est ignoré et journalisé.
 */
interface SwapProvider
{
    /**
     * Demandes d'échange filtrées par magasins et par date de demande (null = pas de filtre ; bornes incluses,
     * format Y-m-d), quel que soit leur statut.
     *
     * @param list<int>|null $storeIds
     * @return list<Swap>
     */
    public function swaps(?array $storeIds, ?string $from = null, ?string $to = null): array;
}
