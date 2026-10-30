<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Models\RoleAssignment;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Repositories\StoreRepositoryInterface;
use App\Models\StoreUser;
use App\Repositories\UserRepositoryInterface;
use App\Models\UserShiftTypeRate;
use App\Services\RoleAssignmentSyncService;
use App\Services\StoreStatsService;

/**
 * Régression : multiStoreComparison() lisait des clés imbriquées
 * ($stats['planning']['totalShifts'], $stats['financial']['avgHourlyCost']...)
 * qui n'ont jamais existé dans le retour PLAT de storeStats() (n, totalNetHours,
 * avgCostPerHour...) — la méthode retournait donc des zéros/null partout, et
 * n'était appelée nulle part (code mort jusqu'ici).
 */
final class StoreStatsServiceMultiStoreComparisonTest extends TestCase
{
    private StoreRepositoryInterface $stores;
    private StoreStatsService $service;

    protected function setUp(): void
    {
        $this->stores     = $this->createStub(StoreRepositoryInterface::class);
        kintai_test_shift_types([]);

        $this->service = new StoreStatsService(
            $this->stores,
            $this->createStub(UserRepositoryInterface::class),
            new RoleAssignmentSyncService(
            ),
        );
    }

    public function testReturnsFlatMetricsPerStoreWithNames(): void
    {
        $this->stores->method('findById')->willReturnMap([
            [1, ['id' => 1, 'name' => 'Store A', 'currency' => 'JPY', 'currency_symbol_style' => 'kanji']],
            [2, ['id' => 2, 'name' => 'Store B']],
        ]);
        kintai_test_store_users(findByStore: fn(int $id) => array_column([
            [1, [['user_id' => 10], ['user_id' => 11]]],
            [2, []],
        ], 1, 0)[$id] ?? []);
        $shiftsByStore = [
            1 => [
                [
                    'id' => 1, 'store_id' => 1, 'user_id' => 10, 'shift_date' => date('Y-m-d'),
                    'start_time' => '09:00', 'end_time' => '17:00', 'cross_midnight' => 0,
                    'duration_minutes' => 480, 'pause_minutes' => 60, 'deleted_at' => null,
                ],
            ],
            2 => [],
        ];
        kintai_test_shifts(array_merge(...array_values($shiftsByStore)));

        $rows = $this->service->multiStoreComparison([1, 2], 30);

        $this->assertCount(2, $rows);
        $this->assertSame('Store A', $rows[0]['store_name']);
        $this->assertSame('JPY', $rows[0]['currency']);
        $this->assertSame(1, $rows[0]['total_shifts']);
        $this->assertSame(2, $rows[0]['employee_count']);
        $this->assertGreaterThan(0, $rows[0]['total_hours']);

        $this->assertSame('Store B', $rows[1]['store_name']);
        $this->assertSame('EUR', $rows[1]['currency'], 'store sans devise configurée retombe sur EUR par défaut');
        $this->assertSame(0, $rows[1]['total_shifts']);
        $this->assertSame(0, $rows[1]['employee_count']);
    }
}
