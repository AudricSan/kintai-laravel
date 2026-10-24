<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\ShiftRepositoryInterface;
use kintai\Core\Repositories\ShiftTypeRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Repositories\UserShiftTypeRateRepositoryInterface;
use kintai\Core\Services\RoleAssignmentSyncService;
use kintai\Core\Services\StoreStatsService;

/**
 * Régression : storeStats() lisait created_by/shift_type_id sans ??/empty(),
 * ce qui déclenchait un warning "Undefined array key" et, pire, pouvait
 * attribuer silencieusement des shifts au manager "0" / au type "Non défini"
 * quand ces clés sont absentes (ex : lu via multiStoreComparison()).
 */
final class StoreStatsServiceStoreStatsTest extends TestCase
{
    private ShiftRepositoryInterface $shifts;
    private StoreStatsService $service;

    protected function setUp(): void
    {
        $this->shifts = $this->createStub(ShiftRepositoryInterface::class);

        $storeUsers = $this->createStub(StoreUserRepositoryInterface::class);
        $storeUsers->method('findByStore')->willReturn([['user_id' => 10]]);

        $shiftTypes = $this->createStub(ShiftTypeRepositoryInterface::class);
        $shiftTypes->method('findByStore')->willReturn([]);

        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findAll')->willReturn([]);

        $userRates = $this->createStub(UserShiftTypeRateRepositoryInterface::class);
        $userRates->method('findByUser')->willReturn([]);

        $this->service = new StoreStatsService(
            $this->createStub(StoreRepositoryInterface::class),
            $this->shifts,
            $shiftTypes,
            $storeUsers,
            $userRates,
            $users,
            new RoleAssignmentSyncService(
                $this->createStub(RoleRepositoryInterface::class),
                $this->createStub(RoleAssignmentRepositoryInterface::class),
            ),
        );
    }

    public function testMissingCreatedByAndShiftTypeIdDoNotWarnAndFallBackToDefaults(): void
    {
        $this->shifts->method('findByStoreBetween')->willReturn([
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
