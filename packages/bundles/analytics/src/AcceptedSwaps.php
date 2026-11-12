<?php

namespace Kintai\Bundles\Analytics;

use Carbon\CarbonImmutable;
use Kintai\Contracts\Shifts\ShiftSwapStatistics;
use Kintai\Contracts\Shifts\ShiftSwapSummary;

/**
 * Échanges de shifts d'un magasin, lus par le contrat (`ShiftSwapStatistics`) : sans bundle d'échanges, il n'y en a aucun.
 */
class AcceptedSwaps
{
    public function __construct(private readonly ShiftSwapStatistics $statistics) {}

    /**
     * Nombre d'échanges acceptés créés entre les deux jours (inclus), par employé : le demandeur et le collègue sollicité
     * comptent chacun l'échange.
     *
     * @return array<int, int>
     */
    public function countByUser(int $storeId, string $since, string $until): array
    {
        $counts = [];

        foreach ($this->statistics->swapsFor($storeId, CarbonImmutable::parse($since), CarbonImmutable::parse($until)) as $swap) {
            if ($swap->status !== ShiftSwapSummary::ACCEPTED) {
                continue;
            }

            $counts[$swap->requesterId] = ($counts[$swap->requesterId] ?? 0) + 1;
            $counts[$swap->targetUserId] = ($counts[$swap->targetUserId] ?? 0) + 1;
        }

        return $counts;
    }
}
