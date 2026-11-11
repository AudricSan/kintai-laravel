<?php

namespace App\Planning;

use Carbon\CarbonInterface;
use Kintai\Contracts\Planning\TimeOffStatistics;

/**
 * Sans bundle de congés : aucune demande.
 */
class NullTimeOffStatistics implements TimeOffStatistics
{
    public function requestsFor(int $storeId, array $userIds, CarbonInterface $from, CarbonInterface $to): array
    {
        return [];
    }
}
