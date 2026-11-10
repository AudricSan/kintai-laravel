<?php

namespace Kintai\Bundles\Analytics;

use Carbon\CarbonImmutable;
use Kintai\Contracts\Planning\TimeOffProvider;

/**
 * Congés approuvés d'un magasin, lus par le contrat (`TimeOffProvider`) : sans bundle de congés, il n'y en a aucun. Le
 * contrat ne publie que les congés approuvés, pas les demandes en attente, refusées ou annulées.
 */
class ApprovedAbsences
{
    public function __construct(private readonly TimeOffProvider $provider) {}

    /**
     * Congés approuvés qui commencent le jour donné ou après (comme les statistiques d'origine), avec leurs jours.
     *
     * @param  list<int>  $userIds
     * @return list<array{user_id: int, start: string, end: string, days: int}>
     */
    public function startingFrom(int $storeId, array $userIds, string $since): array
    {
        $from = CarbonImmutable::parse($since);
        $to = $from->addYears(2);
        $absences = [];

        foreach (array_unique($userIds) as $userId) {
            foreach ($this->provider->approvedFor($userId, $storeId, $from, $to) as $period) {
                if ($period->startDate < $since) {
                    continue;
                }

                $absences[] = [
                    'user_id' => $userId,
                    'start' => $period->startDate,
                    'end' => $period->endDate,
                    'days' => StatsMath::inclusiveDays($period->startDate, $period->endDate),
                ];
            }
        }

        return $absences;
    }
}
