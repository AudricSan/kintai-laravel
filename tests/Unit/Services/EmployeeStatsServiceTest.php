<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Shift;
use App\Models\ShiftType;
use App\Repositories\StoreRepositoryInterface;
use App\Repositories\StoreUserRepositoryInterface;
use App\Models\UserShiftTypeRate;
use App\Services\EmployeeStatsService;
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
    use \Tests\Support\UserRateExpectations;
    private StoreUserRepositoryInterface&Stub $storeUsers;
    private StoreRepositoryInterface&Stub $stores;
    private EmployeeStatsService $service;

    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();
        $this->storeUsers = $this->createStub(StoreUserRepositoryInterface::class);
        $this->stores = $this->createStub(StoreRepositoryInterface::class);

        $this->service = new EmployeeStatsService(
            $this->storeUsers,
            $this->stores,
        );

        kintai_test_shift_types([
            ['id' => 1, 'name' => 'Matin', 'hourly_rate' => 1000.0],
        ]);
        $this->storeUsers->method('findByUser')->willReturn([]);
    }

    private function shift(array $overrides = []): array
    {
        return array_merge([
            'user_id'        => 1,
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
        kintai_test_shifts([
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
        kintai_test_shifts([$this->shift()]);

        $stats = $this->service->calculate(1);

        $this->assertEquals(7.0, $stats['hours_month']); // 8h brut - 1h pause
        $this->assertSame(7000.0, $stats['estimated_pay']); // 7h × 1000
    }

    public function testPersonalRateOverridesShiftTypeRate(): void
    {
        $this->seedRate(1, 1, 1500.0);
        $this->service = new EmployeeStatsService(
            $this->storeUsers,
            $this->stores,
        );
        kintai_test_shifts([$this->shift()]);

        $stats = $this->service->calculate(1);

        $this->assertSame(10500.0, $stats['estimated_pay']); // 7h × 1500
    }

    public function testIgnoresShiftsOutsideRequestedMonth(): void
    {
        kintai_test_shifts([
            $this->shift(['shift_date' => '2020-01-05']),
        ]);

        $stats = $this->service->calculate(1);

        $this->assertEquals(0.0, $stats['hours_month']);
        $this->assertSame(0.0, $stats['estimated_pay']);
        $this->assertFalse($stats['has_rate']);
    }
}
