<?php

namespace Kintai\Contracts\Planning;

use Carbon\CarbonInterface;

/**
 * Demandes de congé d'un magasin, tous statuts confondus, pour les statistiques (depuis 1.7). Le Core n'a pas de congés :
 * il lie une implémentation vide et le bundle timeoff la remplace tant qu'il est activé. `TimeOffProvider` ne publie que
 * les congés approuvés ; ce contrat y ajoute les demandes en attente, refusées et annulées.
 */
interface TimeOffStatistics
{
    /**
     * Demandes de ces employés (identifiants) dans ce magasin (identifiant) qui touchent la période (jours locaux, bornes
     * incluses), quel que soit leur statut, triées par date de début puis identifiant. Sans employé, rien.
     *
     * @param  list<int>  $userIds
     * @return list<TimeOffRequestSummary>
     */
    public function requestsFor(int $storeId, array $userIds, CarbonInterface $from, CarbonInterface $to): array;
}
