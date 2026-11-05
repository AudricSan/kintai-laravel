<?php

namespace Kintai\Bundles\Timeoff;

use Carbon\CarbonInterface;
use Kintai\Bundles\Timeoff\Models\TimeoffRequest;
use Kintai\Contracts\Planning\TimeOffChecker;
use Kintai\Contracts\Planning\TimeOffPeriod;
use Kintai\Contracts\Planning\TimeOffProvider;

/**
 * Les congés approuvés vus par le Core : le garde-fou du planning (un shift sur un congé approuvé est bloqué) et le flux
 * iCal (congés en journée entière). Le bundle remplace les liaisons par défaut du Core (qui ne connaît aucun congé) tant
 * qu'il est activé.
 */
class ApprovedTimeOff implements TimeOffChecker, TimeOffProvider
{
    public function hasApprovedTimeOff(int $userId, CarbonInterface $date): bool
    {
        $day = $date->toDateString();

        return TimeoffRequest::query()
            ->where('user_id', $userId)
            ->where('status', TimeoffRequest::APPROVED)
            ->whereDate('start_date', '<=', $day)
            ->whereDate('end_date', '>=', $day)
            ->exists();
    }

    public function approvedFor(int $userId, int $storeId, CarbonInterface $from, CarbonInterface $to): array
    {
        return TimeoffRequest::query()
            ->where('user_id', $userId)
            ->where('store_id', $storeId)
            ->where('status', TimeoffRequest::APPROVED)
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereDate('end_date', '>=', $from->toDateString())
            ->orderBy('start_date')
            ->orderBy('id')
            ->get()
            ->map(fn (TimeoffRequest $request): TimeOffPeriod => new TimeOffPeriod(
                'timeoff-'.$request->id, $request->start_date->toDateString(), $request->end_date->toDateString(),
            ))
            ->all();
    }
}
