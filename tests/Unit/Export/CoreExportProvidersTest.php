<?php

declare(strict_types=1);

namespace Tests\Unit\Export;

use kintai\Contract\Provider\ExportQuery;
use App\Export\AvailabilitiesExport;
use App\Export\EmployeesExport;
use App\Export\ExportDirectory;
use App\Export\PayrollExport;
use App\Export\ShiftsExport;
use App\Export\ShiftTypesExport;
use App\Export\StoresExport;
use App\Models\Availability;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Models\UserShiftTypeRate;
use App\Services\ScheduleExportService;
use App\Services\ShiftWageCalculator;
use PHPUnit\Framework\TestCase;

/** Fournisseurs de lignes du Core : ils ne lisent que le périmètre reçu et n'exposent aucune donnée sensible. */
final class CoreExportProvidersTest extends TestCase
{
    use \Tests\Support\UserRateExpectations;

    private ExportDirectory $directory;

    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();
        kintai_test_users(findById: fn(int $id): ?array => [
            5 => ['id' => 5, 'first_name' => 'Aki', 'last_name' => 'Sato', 'display_name' => '', 'email' => 'aki@example.test', 'password_hash' => 'SECRET', 'remember_token' => 'TOKEN', 'bio' => 'privé', 'is_active' => 1],
            6 => ['id' => 6, 'first_name' => 'Ben', 'last_name' => 'Roy', 'display_name' => 'Benny', 'email' => 'ben@example.test', 'is_active' => 1],
        ][$id] ?? null);
        kintai_test_stores(findById: fn(int $id): ?array => $id === 3
            ? ['id' => 3, 'code' => 'ST', 'name' => 'Shibuya', 'timezone' => 'Asia/Tokyo', 'currency' => 'JPY', 'api_token' => 'SECRET'] : null);
        $this->directory = new ExportDirectory();
        kintai_test_shifts([
            ['id' => 1, 'user_id' => 5, 'store_id' => 3, 'shift_date' => '2026-11-02', 'start_time' => '09:00', 'end_time' => '17:00', 'pause_minutes' => 60, 'duration_minutes' => 480, 'shift_type_id' => 7, 'notes' => 'a'],
            ['id' => 2, 'user_id' => 6, 'store_id' => 3, 'shift_date' => '2026-11-01', 'start_time' => '10:00', 'end_time' => '14:00', 'pause_minutes' => 0, 'duration_minutes' => 240, 'shift_type_id' => 7],
            ['id' => 3, 'user_id' => 5, 'store_id' => 3, 'shift_date' => '2026-11-03', 'start_time' => '09:00', 'end_time' => '17:00', 'deleted_at' => '2026-10-30 10:00:00'],
        ]);
        kintai_test_shift_types([['id' => 7, 'name' => 'Matin', 'start_time' => '06:00:00', 'end_time' => '14:00:00', 'hourly_rate' => 1200, 'store_ids' => [3]]]);
    }

    private function query(?array $userIds = null): ExportQuery
    {
        return new ExportQuery([3], $userIds, '2026-11-01', '2026-11-30');
    }

    public function testShiftsAreFlatChronologicalAndLimitedToTheChosenPeople(): void
    {
        $export = new ShiftsExport(new ScheduleExportService(), $this->directory);

        $all = [...$export->rows($this->query())];
        $this->assertSame(['2026-11-01', '2026-11-02'], array_column($all, 'date'), 'trié, shift supprimé exclu');
        $this->assertSame(['Benny', 'Sato Aki'], array_column($all, 'employee'), 'le nom affiché prime, sinon nom puis prénom');

        $only = [...$export->rows($this->query([5]))];
        $this->assertCount(1, $only);
        $this->assertSame(420, $only[0]['net_minutes']);
        $this->assertSame('Matin', $only[0]['shift_type']);
        $this->assertSame('Shibuya', $only[0]['store']);

        $this->assertSame([], [...$export->rows($this->query([]))], 'liste vide de personnes : aucune ligne');
    }

    public function testEmployeesNeverExposeSecrets(): void
    {
        kintai_test_store_users(findByStore: fn() => [['user_id' => 5, 'is_active' => 1], ['user_id' => 6, 'is_active' => 0], ['user_id' => 99]]);
        $rows = [...(new EmployeesExport($this->directory))->rows($this->query())];

        $this->assertSame(['Roy', 'Sato'], array_column($rows, 'last_name'), 'compte inconnu ignoré, tri par nom');
        $json = json_encode($rows);
        foreach (['SECRET', 'TOKEN', 'password', 'remember', 'bio', 'privé'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) $json);
        }
        $this->assertFalse($rows[0]['active'], 'appartenance inactive');
        $this->assertTrue($rows[1]['active']);
    }

    public function testStoresAndShiftTypesDoNotLeakSettingsOrRates(): void
    {
        $stores = [...(new StoresExport($this->directory))->rows($this->query())];
        $types  = [...(new ShiftTypesExport($this->directory))->rows($this->query())];

        $this->assertSame('Asia/Tokyo', $stores[0]['timezone']);
        $this->assertStringNotContainsString('SECRET', (string) json_encode($stores));
        $this->assertSame('06:00', $types[0]['start_time']);
        $this->assertArrayNotHasKey('hourly_rate', $types[0], 'les taux sont des données de paie');
    }

    public function testAvailabilitiesAreFilteredByPerson(): void
    {
        \Illuminate\Support\Facades\DB::table('stores')->insertOrIgnore(['id' => 3, 'name' => 'Magasin 3', 'code' => 'M3']);
        foreach ([
            ['user_id' => 5, 'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '17:00', 'is_available' => 1],
            ['user_id' => 6, 'day_of_week' => 2, 'start_time' => '10:00', 'end_time' => '12:00', 'is_available' => 0],
        ] as $row) {
            kintai_test_user($row['user_id']);
            \App\Models\Availability::create($row + ['store_id' => 3]);
        }
        $export = new AvailabilitiesExport($this->directory);

        $rows = [...$export->rows($this->query([6]))];

        $this->assertCount(1, $rows);
        $this->assertSame('Benny', $rows[0]['employee']);
        $this->assertFalse($rows[0]['available']);
    }

    public function testPayrollUsesThePersonalRateBeforeTheTypeRate(): void
    {
        $this->seedRate(5, 7, 2000.0);
        $export = new PayrollExport(new ShiftWageCalculator(), $this->directory);

        $rows = [...$export->rows($this->query())];

        $byEmployee = array_column($rows, null, 'employee');
        $this->assertEqualsWithDelta(2000.0, $byEmployee['Sato Aki']['hourly_rate'], 0.01, 'taux personnel');
        $this->assertEqualsWithDelta(1200.0, $byEmployee['Benny']['hourly_rate'], 0.01, 'taux du type');
        $this->assertSame('JPY', $rows[0]['currency']);
        $this->assertCount(2, $rows, 'shift supprimé exclu');
    }
}
