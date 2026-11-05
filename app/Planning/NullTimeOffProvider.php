<?php

namespace App\Planning;

use Carbon\CarbonInterface;
use Kintai\Contracts\Planning\TimeOffProvider;

/**
 * Sans bundle de congés : aucun congé à publier.
 */
class NullTimeOffProvider implements TimeOffProvider
{
    public function approvedFor(int $userId, int $storeId, CarbonInterface $from, CarbonInterface $to): array
    {
        return [];
    }
}
