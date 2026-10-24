<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Export;

use kintai\Contract\Provider\ExportQuery;
use kintai\Core\Export\AvailabilitiesExport;
use kintai\Core\Export\EmployeesExport;
use kintai\Core\Export\ExportDirectory;
use kintai\Core\Export\PayrollExport;
use kintai\Core\Export\ShiftsExport;
use kintai\Core\Export\ShiftTypesExport;
use kintai\Core\Export\StoresExport;
use kintai\Core\Repositories\AvailabilityRepositoryInterface;
use kintai\Core\Repositories\ShiftRepositoryInterface;
use kintai\Core\Repositories\ShiftTypeRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Repositories\UserShiftTypeRateRepositoryInterface;
use kintai\Core\Services\ScheduleExportService;
use kintai\Core\Services\ShiftWageCalculator;
use PHPUnit\Framework\TestCase;

/** Fournisseurs de lignes du Core : ils ne lisent que le périmètre reçu et n'exposent aucune donnée sensible. */
final class CoreExportProvidersTest extends TestCase
{
    private ExportDirectory $directory;
    private ShiftRepositoryInterface $shifts;
    private ShiftTypeRepositoryInterface $types;

    protected function setUp(): void
    {
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturnCallback(fn(int $id): ?array => [
            5 => ['id' => 5, 'first_name' => 'Aki', 'last_name' => 'Sato', 'display_name' => '', 'email' => 'aki@example.test', 'password_hash' => 'SECRET', 'remember_token' => 'TOKEN', 'bio' => 'privé', 'is_active' => 1],
            6 => ['id' => 6, 'first_name' => 'Ben', 'last_name' => 'Roy', 'display_name' => 'Benny', 'email' => 'ben@example.test', 'is_active' => 1],
        ][$id] ?? null);
        $stores = $this->createStub(StoreRepositoryInterface::class);
        $stores->method('findById')->willReturnCallback(fn(int $id): ?array => $id === 3
            ? ['id' => 3, 'code' => 'ST', 'name' => 'Shibuya', 'timezone' => 'Asia/Tokyo', 'currency' => 'JPY', 'api_token' => 'SECRET'] : null);
        $this->directory = new ExportDirectory($users, $stores);

        $this->shifts = $this->createStub(ShiftRepositoryInterface::class);
        $this->shifts->method('findByStoreBetween')->willReturn([
            ['id' => 1, 'user_id' => 5, 'store_id' => 3, 'shift_date' => '2026-11-02', 'start_time' => '09:00', 'end_time' => '17:00', 'pause_minutes' => 60, 'duration_minutes' => 480, 'shift_type_id' => 7, 'notes' => 'a'],
            ['id' => 2, 'user_id' => 6, 'store_id' => 3, 'shift_date' => '2026-11-01', 'start_time' => '10:00', 'end_time' => '14:00', 'pause_minutes' => 0, 'duration_minutes' => 240, 'shift_type_id' => 7],
            ['id' => 3, 'user_id' => 5, 'store_id' => 3, 'shift_date' => '2026-11-03', 'start_time' => '09:00', 'end_time' => '17:00', 'deleted_at' => '2026-10-30 10:00:00'],
        ]);
        $this->types = $this->createStub(ShiftTypeRepositoryInterface::class);
        $this->types->method('findAll')->willReturn([['id' => 7, 'name' => 'Matin', 'start_time' => '06:00', 'end_time' => '22:00', 'hourly_rate' => 1200]]);
        $this->types->method('findByStore')->willReturn([['id' => 7, 'name' => 'Matin', 'start_time' => '06:00:00', 'end_time' => '14:00:00', 'hourly_rate' => 1200]]);
    }

    private function query(?array $userIds = null): ExportQuery
    {
        return new ExportQuery([3], $userIds, '2026-11-01', '2026-11-30');
    }

    public function testShiftsAreFlatChronologicalAndLimitedToTheChosenPeople(): void
    {
        $export = new ShiftsExport($this->shifts, $this->types, new ScheduleExportService(), $this->directory);

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
        $storeUsers = $this->createStub(StoreUserRepositoryInterface::class);
        $storeUsers->method('findByStore')->willReturn([['user_id' => 5, 'is_active' => 1], ['user_id' => 6, 'is_active' => 0], ['user_id' => 99]]);

        $rows = [...(new EmployeesExport($storeUsers, $this->directory))->rows($this->query())];

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
        $types  = [...(new ShiftTypesExport($this->types, $this->directory))->rows($this->query())];

        $this->assertSame('Asia/Tokyo', $stores[0]['timezone']);
        $this->assertStringNotContainsString('SECRET', (string) json_encode($stores));
        $this->assertSame('06:00', $types[0]['start_time']);
        $this->assertArrayNotHasKey('hourly_rate', $types[0], 'les taux sont des données de paie');
    }

    public function testAvailabilitiesAreFilteredByPerson(): void
    {
        $repo = $this->createStub(AvailabilityRepositoryInterface::class);
        $repo->method('findByStore')->willReturn([
            ['user_id' => 5, 'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '17:00', 'is_available' => 1],
            ['user_id' => 6, 'day_of_week' => 2, 'start_time' => '10:00', 'end_time' => '12:00', 'is_available' => 0],
        ]);
        $export = new AvailabilitiesExport($repo, $this->directory);

        $rows = [...$export->rows($this->query([6]))];

        $this->assertCount(1, $rows);
        $this->assertSame('Benny', $rows[0]['employee']);
        $this->assertFalse($rows[0]['available']);
    }

    public function testPayrollUsesThePersonalRateBeforeTheTypeRate(): void
    {
        $rates = $this->createStub(UserShiftTypeRateRepositoryInterface::class);
        $rates->method('findByUser')->willReturnCallback(fn(int $id): array => $id === 5 ? [['shift_type_id' => 7, 'hourly_rate' => 2000]] : []);
        $export = new PayrollExport($this->shifts, $this->types, $rates, new ShiftWageCalculator(), $this->directory);

        $rows = [...$export->rows($this->query())];

        $byEmployee = array_column($rows, null, 'employee');
        $this->assertEqualsWithDelta(2000.0, $byEmployee['Sato Aki']['hourly_rate'], 0.01, 'taux personnel');
        $this->assertEqualsWithDelta(1200.0, $byEmployee['Benny']['hourly_rate'], 0.01, 'taux du type');
        $this->assertSame('JPY', $rows[0]['currency']);
        $this->assertCount(2, $rows, 'shift supprimé exclu');
    }
}
