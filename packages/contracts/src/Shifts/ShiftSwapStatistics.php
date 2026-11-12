<?php

namespace Kintai\Contracts\Shifts;

use Carbon\CarbonInterface;

/**
 * Demandes d'échange de shifts d'un magasin, tous statuts confondus, pour les statistiques (depuis 1.7). Le Core n'a pas
 * d'échanges : il lie une implémentation vide et le bundle shift-swap la remplace tant qu'il est activé.
 */
interface ShiftSwapStatistics
{
    /**
     * Demandes du magasin (identifiant) créées pendant la période (jours locaux, bornes incluses), quel que soit leur
     * statut, de la plus ancienne à la plus récente.
     *
     * @return list<ShiftSwapSummary>
     */
    public function swapsFor(int $storeId, CarbonInterface $from, CarbonInterface $to): array;
}
