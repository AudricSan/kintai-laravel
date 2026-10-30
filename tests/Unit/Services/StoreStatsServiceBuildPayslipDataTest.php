<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Models\RoleAssignment;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Models\UserShiftTypeRate;
use App\Services\RoleAssignmentSyncService;
use App\Services\StoreStatsService;

/**
 * Un shift à cheval sur deux types d'horaire (ex : 07:00-18:00 traverse un type
 * 05:00-08:00 puis un type 08:00-22:00) doit être facturé par tranche via
 * ShiftWageCalculator::costOf(), et buildPayslipData() doit exposer le détail
 * (type_breakdown) en plus du total — ce qu'affiche le rapport de salaire.
 */
final class StoreStatsServiceBuildPayslipDataTest extends TestCase
{
    use \Tests\Support\UserRateExpectations;

    public function testSplitsMultiTypeShiftAndExposesTypeBreakdown(): void
    {
        kintai_test_database();
        kintai_test_migrate();
        kintai_test_fk_off();
        Store::saveDeductionSettings(1, ['enabled' => false]);
        kintai_test_shifts([
            [
                'shift_date' => '2026-08-05', 'start_time' => '07:00', 'end_time' => '18:00',
                'duration_minutes' => 660, 'pause_minutes' => 0, 'user_id' => 7, 'shift_type_id' => null,
            ],
        ]);
        kintai_test_shift_types([
            ['id' => 1, 'name' => 'Tôt',  'start_time' => '05:00', 'end_time' => '08:00', 'hourly_rate' => 1200.0],
            ['id' => 2, 'name' => 'Jour', 'start_time' => '08:00', 'end_time' => '22:00', 'hourly_rate' => 1000.0],
        ]);
        kintai_test_store_users(findMembership: fn() => null);
        $service = new StoreStatsService(
            new RoleAssignmentSyncService(
            ),
        );

        $data = $service->buildPayslipData(1, 7, '2026-08-01', '2026-08-31');

        // 1h × 1200 (Tôt) + 10h × 1000 (Jour) = 11200, pas 11h × un seul taux.
        $this->assertSame(11200.0, $data['totalCost']);
        $this->assertSame(660, $data['totalNetMin']);

        $breakdown = array_column($data['type_breakdown'], null, 'name');
        $this->assertSame(60, $breakdown['Tôt']['minutes']);
        $this->assertSame(600, $breakdown['Jour']['minutes']);
        $this->assertSame(1200.0, $breakdown['Tôt']['amount']);
        $this->assertSame(10000.0, $breakdown['Jour']['amount']);
    }
}
