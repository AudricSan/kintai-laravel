<?php

namespace App\Planning;

use Carbon\CarbonInterface;
use Kintai\Contracts\Planning\TimeOffChecker;

/**
 * Implémentation par défaut : sans bundle de congés, personne n'est jamais en congé.
 */
class NullTimeOffChecker implements TimeOffChecker
{
    public function hasApprovedTimeOff(int $userId, CarbonInterface $date): bool
    {
        return false;
    }
}
