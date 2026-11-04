<?php

namespace App\Contracts\Planning;

use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Le Core n'a pas de congés : le bundle timeoff remplace l'implémentation liée dans le conteneur.
 */
interface TimeOffChecker
{
    /**
     * L'employé a-t-il un congé approuvé couvrant ce jour (local) ?
     */
    public function hasApprovedTimeOff(User $user, CarbonInterface $date): bool;
}
