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
 * Régression : storeStats() lisait created_by/shift_type_id sans ??/empty(),
 * ce qui déclenchait un warning "Undefined array key" et, pire, pouvait
 * attribuer silencieusement des shifts au manager "0" / au type "Non défini"
 * quand ces clés sont absentes (ex : lu via multiStoreComparison()).
 */
final class StoreStatsServiceStoreStatsTest extends TestCase
{
    use \Tests\Support\UserRateExpectations;
    private StoreStatsService $service;

    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();
        kintai_test_store_users(findByStore: fn() => [['user_id' => 10]]);
        kintai_test_shift_types([]);

        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findAll')->willReturn([]);

        $this->service = new StoreStatsService(
            $this->createStub(StoreRepositoryInterface::class),
            $users,
            new RoleAssignmentSyncService(
            ),
        );
    }

    public function testMissingCreatedByAndShiftTypeIdDoNotWarnAndFallBackToDefaults(): void
    {
        kintai_test_shifts([
            [
                'id' => 1, 'user_id' => 10, 'shift_date' => date('Y-m-d'),
                'start_time' => '09:00', 'end_time' => '17:00', 'cross_midnight' => 0,
                'duration_minutes' => 480, 'pause_minutes' => 60, 'deleted_at' => null,
                // pas de 'created_by' ni 'shift_type_id'
            ],
        ]);

        $stats = $this->service->storeStats(1, 30);

        $this->assertSame(1, $stats['shiftsCreatedByManager'][0] ?? null, 'shift sans created_by attribué au manager 0');
        $this->assertArrayHasKey('Non défini', $stats['hoursByType']);
        $this->assertSame(1, $stats['n']);
    }
}
