<?php

namespace Kintai\Contracts\Planning;

use Carbon\CarbonInterface;

/**
 * Le Core n'a pas de congés : le bundle timeoff remplace l'implémentation liée dans le conteneur.
 */
interface TimeOffChecker
{
    /**
     * L'employé a-t-il un congé approuvé couvrant ce jour (local) ?
     */
    public function hasApprovedTimeOff(int $userId, CarbonInterface $date): bool;
}
