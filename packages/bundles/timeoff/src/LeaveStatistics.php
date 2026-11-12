<?php

namespace Kintai\Bundles\Timeoff;

use Carbon\CarbonInterface;
use Kintai\Bundles\Timeoff\Models\TimeoffRequest;
use Kintai\Contracts\Planning\TimeOffRequestSummary;
use Kintai\Contracts\Planning\TimeOffStatistics;

/**
 * Les demandes de congé, tous statuts, lues par les statistiques d'un autre bundle (analytics) : le bundle remplace la
 * liaison vide du Core tant qu'il est activé.
 */
class LeaveStatistics implements TimeOffStatistics
{
    public function requestsFor(int $storeId, array $userIds, CarbonInterface $from, CarbonInterface $to): array
    {
        if ($userIds === []) {
            return [];
        }

        return TimeoffRequest::query()
            ->where('store_id', $storeId)
            ->whereIn('user_id', array_values(array_unique($userIds)))
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereDate('end_date', '>=', $from->toDateString())
            ->orderBy('start_date')
            ->orderBy('id')
            ->get()
            ->map(fn (TimeoffRequest $request): TimeOffRequestSummary => new TimeOffRequestSummary(
                $request->user_id, $request->start_date->toDateString(), $request->end_date->toDateString(), $request->status, $request->type,
            ))
            ->all();
    }
}
