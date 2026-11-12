<?php

namespace Kintai\Contracts\Planning;

use Carbon\CarbonInterface;

/**
 * Congés approuvés d'un employé, publiés en journée entière dans son flux iCal. Le Core n'a pas de congés : le bundle
 * timeoff remplace l'implémentation liée dans le conteneur.
 */
interface TimeOffProvider
{
    /**
     * Congés approuvés de l'employé (identifiant) dans ce magasin (identifiant) qui touchent la période (jours locaux, bornes incluses).
     *
     * @return list<TimeOffPeriod>
     */
    public function approvedFor(int $userId, int $storeId, CarbonInterface $from, CarbonInterface $to): array;
}
