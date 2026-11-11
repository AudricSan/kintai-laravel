<?php

namespace Kintai\Bundles\Analytics;

use Carbon\CarbonImmutable;
use Kintai\Contracts\Planning\TimeOffRequestSummary;
use Kintai\Contracts\Planning\TimeOffStatistics;

/**
 * Congés d'un magasin, lus par le contrat (`TimeOffStatistics`) : sans bundle de congés, il n'y en a aucun. Les demandes
 * de tous les statuts nourrissent la répartition par statut et par type ; les jours d'absence et le taux d'absentéisme ne
 * comptent que les congés approuvés.
 */
class ApprovedAbsences
{
    public function __construct(private readonly TimeOffStatistics $statistics) {}

    /**
     * Demandes, tous statuts, qui commencent le jour donné ou après (comme les statistiques d'origine).
     *
     * @param  list<int>  $userIds
     * @return list<TimeOffRequestSummary>
     */
    public function requestsStartingFrom(int $storeId, array $userIds, string $since): array
    {
        if ($userIds === []) {
            return [];
        }

        $from = CarbonImmutable::parse($since);
        $requests = $this->statistics->requestsFor($storeId, array_values(array_unique($userIds)), $from, $from->addYears(2));

        return array_values(array_filter($requests, fn (TimeOffRequestSummary $request): bool => $request->startDate >= $since));
    }

    /**
     * Congés approuvés qui commencent le jour donné ou après, avec leurs jours.
     *
     * @param  list<int>  $userIds
     * @return list<array{user_id: int, start: string, end: string, days: int}>
     */
    public function startingFrom(int $storeId, array $userIds, string $since): array
    {
        $absences = [];

        foreach ($this->requestsStartingFrom($storeId, $userIds, $since) as $request) {
            if ($request->status !== TimeOffRequestSummary::APPROVED) {
                continue;
            }

            $absences[] = [
                'user_id' => $request->userId,
                'start' => $request->startDate,
                'end' => $request->endDate,
                'days' => StatsMath::inclusiveDays($request->startDate, $request->endDate),
            ];
        }

        return $absences;
    }
}
