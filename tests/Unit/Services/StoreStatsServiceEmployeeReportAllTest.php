<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\StoreStatsService;
use PHPUnit\Framework\TestCase;

/**
 * Le rapport employés agrégé additionne heures, shifts et coûts de chaque magasin, par employé, sans compter deux
 * fois les absences et échanges (lus par employé, pas par magasin).
 */
final class StoreStatsServiceEmployeeReportAllTest extends TestCase
{
    /** @param array<int, array<string, mixed>> $stats uid → stat */
    private function report(array $stats): array
    {
        return [
            'since' => '2026-10-01', 'today' => '2026-10-31', 'period' => 30,
            'usersMap' => array_fill_keys(array_keys($stats), []),
            'membersMap' => array_fill_keys(array_keys($stats), []),
            'memberIds' => array_keys($stats),
            'employeeStats' => $stats,
            'storeTypesMap' => [],
        ];
    }

    private function stat(int $shifts, float $net, float $cost, int $abs = 0): array
    {
        return ['shifts' => $shifts, 'gross_hours' => $net, 'net_hours' => $net, 'cost' => $cost, 'work_days' => $shifts,
            'abs_days' => $abs, 'swaps' => 0, 'max_consec' => $shifts, 'has_rate' => true];
    }

    public function testSumsAnEmployeeWorkingInTwoStores(): void
    {
        $report = StoreStatsService::mergeEmployeeReports([
            1 => $this->report([10 => $this->stat(2, 16.0, 160.0, 2), 11 => $this->stat(0, 0.0, 0.0)]),
            2 => $this->report([10 => $this->stat(3, 24.0, 240.0, 2)]),
        ], ['EUR'], 30);

        $this->assertSame(5, $report['employeeStats'][10]['shifts']);
        $this->assertSame(40.0, $report['employeeStats'][10]['net_hours']);
        $this->assertSame(400.0, $report['employeeStats'][10]['cost']);
        $this->assertSame(2, $report['employeeStats'][10]['abs_days'], 'absences non doublées');
        $this->assertSame(5, $report['totalShifts']);
        $this->assertSame(400.0, $report['totalCost']);
        $this->assertSame(1, $report['activeCount']);
        $this->assertEqualsCanonicalizing([10, 11], $report['memberIds']);
        $this->assertSame(2, $report['statsStoreByUser'][10], 'statistiques ouvertes dans le magasin le plus travaillé');
        $this->assertSame(['EUR'], $report['currencies']);
    }

    public function testMixedCurrenciesHideCost(): void
    {
        $report = StoreStatsService::mergeEmployeeReports([
            1 => $this->report([10 => $this->stat(1, 8.0, 80.0)]),
            2 => $this->report([10 => $this->stat(1, 8.0, 9000.0)]),
        ], ['EUR', 'JPY'], 30);

        $this->assertFalse($report['anyHasRate']);
        $this->assertFalse($report['employeeStats'][10]['has_rate']);
    }
}
