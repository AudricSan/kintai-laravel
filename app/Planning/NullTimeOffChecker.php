<?php

namespace App\Planning;

use App\Contracts\Planning\TimeOffChecker;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Implémentation par défaut : sans bundle de congés, personne n'est jamais en congé.
 */
class NullTimeOffChecker implements TimeOffChecker
{
    public function hasApprovedTimeOff(User $user, CarbonInterface $date): bool
    {
        return false;
    }
}
