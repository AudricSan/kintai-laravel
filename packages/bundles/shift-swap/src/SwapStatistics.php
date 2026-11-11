<?php

namespace Kintai\Bundles\ShiftSwap;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Kintai\Bundles\ShiftSwap\Models\ShiftSwapRequest;
use Kintai\Contracts\Shifts\ShiftSwapStatistics;
use Kintai\Contracts\Shifts\ShiftSwapSummary;

/**
 * Les demandes d'échange, tous statuts, lues par les statistiques d'un autre bundle (analytics) : le bundle remplace la
 * liaison vide du Core tant qu'il est activé.
 */
class SwapStatistics implements ShiftSwapStatistics
{
    public function swapsFor(int $storeId, CarbonInterface $from, CarbonInterface $to): array
    {
        return ShiftSwapRequest::query()
            ->where('store_id', $storeId)
            ->where('created_at', '>=', $from->copy()->startOfDay()->setTimezone(config('app.timezone')))
            ->where('created_at', '<=', $to->copy()->endOfDay()->setTimezone(config('app.timezone')))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (ShiftSwapRequest $swap): ShiftSwapSummary => new ShiftSwapSummary(
                $swap->requester_id, $swap->target_user_id, $swap->status, CarbonImmutable::instance($swap->created_at),
            ))
            ->all();
    }
}
