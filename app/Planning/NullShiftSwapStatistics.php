<?php

namespace App\Planning;

use Carbon\CarbonInterface;
use Kintai\Contracts\Shifts\ShiftSwapStatistics;

/**
 * Sans bundle d'échanges de shifts : aucune demande.
 */
class NullShiftSwapStatistics implements ShiftSwapStatistics
{
    public function swapsFor(int $storeId, CarbonInterface $from, CarbonInterface $to): array
    {
        return [];
    }
}
