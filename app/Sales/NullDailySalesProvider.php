<?php

namespace App\Sales;

use Carbon\CarbonInterface;
use Kintai\Contracts\Sales\DailySalesProvider;

/**
 * Sans bundle de rapports journaliers : aucune vente.
 */
class NullDailySalesProvider implements DailySalesProvider
{
    public function dailySales(int $storeId, CarbonInterface $from, CarbonInterface $to, ?array $statuses = null): array
    {
        return [];
    }

    public function available(): bool
    {
        return false;
    }
}
