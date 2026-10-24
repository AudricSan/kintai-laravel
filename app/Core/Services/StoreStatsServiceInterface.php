<?php

declare(strict_types=1);

namespace kintai\Core\Services;

interface StoreStatsServiceInterface
{
    public function storeStats(int $storeId, int $period, int $minShiftMin = 0, int $maxShiftMin = 0): array;

    /**
     * Coût salarial par mois (« YYYY-MM » => montant) sur les $period derniers jours : exactement
     * storeStats($storeId, $period)['costByMonth'], sans calculer tout le reste des statistiques.
     *
     * @return array<string, float>
     */
    public function costByMonth(int $storeId, int $period): array;

    public function storeStatsExportData(int $storeId, int $period): array;

    public function storeProfitability(int $storeId, string $from, string $to): array;

    public function employeeStats(int $storeId, int $userId, int $period): array;

    public function employeeReport(int $storeId, int $period): array;

    public function buildPayslipData(int $storeId, int $userId, string $from, string $to): array;

    /** Comparaison multi-magasins des KPIs clés. */
    public function multiStoreComparison(array $storeIds, int $period): array;
}
