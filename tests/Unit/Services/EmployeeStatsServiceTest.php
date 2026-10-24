<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Services;

use kintai\Core\Repositories\ShiftRepositoryInterface;
use kintai\Core\Repositories\ShiftTypeRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserShiftTypeRateRepositoryInterface;
use kintai\Core\Services\EmployeeStatsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Le montant "Salaire est." de cette classe alimente à la fois le dashboard employé
 * et la colonne de la liste du staff (AdminUserController) : ces tests verrouillent
 * la correction de deux bugs qui faisaient diverger ce montant d'une page à l'autre
 * (shifts supprimés comptés, minutes brutes non déduites de la pause).
 */
final class EmployeeStatsServiceTest extends TestCase
{
    private ShiftRepositoryInterface&Stub $shifts;
    private ShiftTypeRepositoryInterface&Stub $shiftTypes;
    private UserShiftTypeRateRepositoryInterface&Stub $userRates;
    private StoreUserRepositoryInterface&Stub $storeUsers;
    private StoreRepositoryInterface&Stub $stores;
    private EmployeeStatsService $service;

    protected function setUp(): void
    {
        $this->shifts = $this->createStub(ShiftRepositoryInterface::class);
        $this->shiftTypes = $this->createStub(ShiftTypeRepositoryInterface::class);
        $this->userRates = $this->createStub(UserShiftTypeRateRepositoryInterface::class);
        $this->storeUsers = $this->createStub(StoreUserRepositoryInterface::class);
        $this->stores = $this->createStub(StoreRepositoryInterface::class);

        $this->service = new EmployeeStatsService(
            $this->shifts,
            $this->shiftTypes,
            $this->userRates,
            $this->storeUsers,
            $this->stores,
        );

        $this->shiftTypes->method('findAll')->willReturn([
            ['id' => 1, 'name' => 'Matin', 'hourly_rate' => 1000.0],
        ]);
        $this->userRates->method('findByUser')->willReturn([]);
        $this->storeUsers->method('findByUser')->willReturn([]);
    }

    private function shift(array $overrides = []): array
    {
        return array_merge([
            'shift_date'     => date('Y-m') . '-05',
            'start_time'     => '08:00',
            'end_time'       => '16:00',
            'duration_minutes' => 480,
            'pause_minutes'  => 60,
            'shift_type_id'  => 1,
            'cross_midnight' => 0,
        ], $overrides);
    }

    public function testExcludesDeletedShifts(): void
    {
        $this->shifts->method('findByUser')->willReturn([
            $this->shift(),
            $this->shift(['shift_date' => date('Y-m') . '-06', 'deleted_at' => '2026-08-01 00:00:00']),
        ]);

        $stats = $this->service->calculate(1);

        // 7h nettes (480 - 60 pause) pour le seul shift non supprimé.
        $this->assertEquals(7.0, $stats['hours_month']);
        $this->assertSame(7000.0, $stats['estimated_pay']);
    }

    public function testDeductsPauseFromNetHours(): void
    {
        $this->shifts->method('findByUser')->willReturn([$this->shift()]);

        $stats = $this->service->calculate(1);

        $this->assertEquals(7.0, $stats['hours_month']); // 8h brut - 1h pause
        $this->assertSame(7000.0, $stats['estimated_pay']); // 7h × 1000
    }

    public function testPersonalRateOverridesShiftTypeRate(): void
    {
        $this->userRates = $this->createStub(UserShiftTypeRateRepositoryInterface::class);
        $this->userRates->method('findByUser')->willReturn([
            ['shift_type_id' => 1, 'hourly_rate' => 1500.0],
        ]);
        $this->service = new EmployeeStatsService(
            $this->shifts,
            $this->shiftTypes,
            $this->userRates,
            $this->storeUsers,
            $this->stores,
        );
        $this->shifts->method('findByUser')->willReturn([$this->shift()]);

        $stats = $this->service->calculate(1);

        $this->assertSame(10500.0, $stats['estimated_pay']); // 7h × 1500
    }

    public function testIgnoresShiftsOutsideRequestedMonth(): void
    {
        $this->shifts->method('findByUser')->willReturn([
            $this->shift(['shift_date' => '2020-01-05']),
        ]);

        $stats = $this->service->calculate(1);

        $this->assertEquals(0.0, $stats['hours_month']);
        $this->assertSame(0.0, $stats['estimated_pay']);
        $this->assertFalse($stats['has_rate']);
    }
}
