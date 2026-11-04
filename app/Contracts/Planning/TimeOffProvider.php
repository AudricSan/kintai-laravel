<?php

namespace App\Contracts\Planning;

use App\Models\Store;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Congés approuvés d'un employé, publiés en journée entière dans son flux iCal. Le Core n'a pas de congés : le bundle
 * timeoff remplace l'implémentation liée dans le conteneur.
 */
interface TimeOffProvider
{
    /**
     * Congés approuvés de l'employé dans ce magasin qui touchent la période (jours locaux, bornes incluses).
     *
     * @return list<TimeOffPeriod>
     */
    public function approvedFor(User $user, Store $store, CarbonInterface $from, CarbonInterface $to): array;
}
