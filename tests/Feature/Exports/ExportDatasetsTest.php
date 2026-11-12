<?php

namespace Tests\Feature\Exports;

use App\Models\Availability;
use App\Models\EmployeeProfile;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kintai\Contracts\Exports\ExportColumn;
use Kintai\Contracts\Exports\ExportDataset;
use Kintai\Contracts\Exports\ExportDatasetRegistry;
use Kintai\Contracts\Exports\ExportScope;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class ExportDatasetsTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;

    private Store $storeA;

    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->storeA = Store::factory()->create(['name' => 'Alpha', 'currency' => 'EUR']);
        $this->storeB = Store::factory()->create(['name' => 'Beta']);
    }

    private function dataset(string $key): ExportDataset
    {
        return app(ExportDatasetRegistry::class)->find($key) ?? $this->fail("Unknown dataset {$key}");
    }

    /**
     * @param  list<int>|null  $stores
     * @param  list<int>|null  $users
     * @param  list<int>  $excluded
     */
    private function scope(?array $stores = null, ?array $users = null, string $from = '2026-11-01', string $to = '2026-11-30', array $excluded = []): ExportScope
    {
        return new ExportScope(
            $this->owner(),
            false,
            $stores ?? [$this->storeA->id, $this->storeB->id],
            $users,
            $excluded,
            CarbonImmutable::parse($from),
            CarbonImmutable::parse($to),
        );
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    private function rows(string $key, ExportScope $scope): array
    {
        $dataset = $this->dataset($key);
        $rows = iterator_to_array($dataset->rows($scope), false);
        $this->assertSame(count($rows), $dataset->count($scope), 'count() must match rows()');

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function shift(User $user, Store $store, string $date, string $start = '09:00', string $end = '17:00', array $attributes = []): Shift
    {
        return Shift::factory()->create($attributes + [
            'user_id' => $user->id,
            'store_id' => $store->id,
            'shift_date' => $date,
            'starts_at' => Shift::localTime($date, $start),
            'ends_at' => Shift::localTime($end <= $start ? CarbonImmutable::parse($date)->addDay()->toDateString() : $date, $end),
            'pause_minutes' => 60,
        ]);
    }

    public function test_every_dataset_declares_translated_columns_that_its_rows_fill(): void
    {
        $employee = $this->employeeOf($this->storeA);
        $this->shift($employee, $this->storeA, '2026-11-10');
        Availability::factory()->create(['user_id' => $employee->id, 'store_id' => $this->storeA->id]);
        $type = ShiftType::factory()->create();
        $this->storeA->shiftTypes()->attach($type);
        $translations = json_decode((string) file_get_contents(lang_path('fr.json')), true);

        foreach (app(ExportDatasetRegistry::class)->all() as $key => $dataset) {
            $this->assertArrayHasKey($dataset->label(), $translations, "Dataset label {$dataset->label()} is not translated");
            $this->assertContains('csv', $dataset->formats());

            $rows = $this->rows($key, $this->scope());
            $this->assertNotEmpty($rows, "Dataset {$key} produced no row");

            foreach ($dataset->columns() as $column) {
                $this->assertArrayHasKey($column->label, $translations, "Column label {$column->label} of {$key} is not translated");
                $this->assertArrayHasKey($column->key, $rows[0], "Row of {$key} misses column {$column->key}");
            }
            $this->assertSame(array_map(fn (ExportColumn $c): string => $c->key, $dataset->columns()), array_keys($rows[0]));
        }
    }

    public function test_shifts_are_filtered_by_store_employee_and_period_and_sorted(): void
    {
        $anna = $this->employeeOf($this->storeA, ['name' => 'Anna']);
        $bob = $this->employeeOf($this->storeA, ['name' => 'Bob']);
        $this->storeB->members()->attach($anna);
        $this->shift($anna, $this->storeA, '2026-11-12', '14:00', '18:00');
        $this->shift($anna, $this->storeA, '2026-11-12', '08:00', '12:00', ['notes' => 'Early']);
        $this->shift($bob, $this->storeA, '2026-11-05');
        $this->shift($anna, $this->storeB, '2026-11-06');
        $this->shift($anna, $this->storeA, '2026-12-01');
        $this->shift($anna, $this->storeA, '2026-10-31');
        $deleted = $this->shift($anna, $this->storeA, '2026-11-07');
        $deleted->delete();
        Shift::factory()->open()->create(['store_id' => $this->storeA->id, 'shift_date' => '2026-11-08']);

        $all = $this->rows('shifts', $this->scope());
        $this->assertSame(
            ['2026-11-05', '2026-11-06', '2026-11-12', '2026-11-12'],
            array_column($all, 'date'),
        );
        $this->assertSame(['Bob', 'Anna', 'Anna', 'Anna'], array_column($all, 'employee'));
        $this->assertSame(['08:00', '14:00'], array_column(array_slice($all, 2), 'start_time'));

        $store = $this->rows('shifts', $this->scope([$this->storeB->id]));
        $this->assertSame(['Beta'], array_column($store, 'store'));

        $person = $this->rows('shifts', $this->scope([$this->storeA->id], [$bob->id]));
        $this->assertSame(['Bob'], array_column($person, 'employee'));

        $period = $this->rows('shifts', $this->scope([$this->storeA->id], null, '2026-11-12', '2026-11-12'));
        $this->assertCount(2, $period);

        $excluded = $this->rows('shifts', $this->scope([$this->storeA->id], null, excluded: [$bob->id]));
        $this->assertNotContains('Bob', array_column($excluded, 'employee'));

        $none = $this->rows('shifts', $this->scope([$this->storeA->id], []));
        $this->assertSame([], $none);
    }

    public function test_shift_rows_carry_local_times_pause_net_minutes_and_midnight(): void
    {
        config(['kintai.timezone' => 'Asia/Tokyo']);
        $employee = $this->employeeOf($this->storeA);
        $type = ShiftType::factory()->create(['name' => 'Night']);
        $this->shift($employee, $this->storeA, '2026-11-10', '22:00', '06:00', ['shift_type_id' => $type->id, 'notes' => 'Late']);

        $row = $this->rows('shifts', $this->scope())[0];

        $this->assertSame('2026-11-10', $row['date']);
        $this->assertSame('22:00', $row['start_time']);
        $this->assertSame('06:00', $row['end_time']);
        $this->assertTrue($row['ends_next_day']);
        $this->assertSame(60, $row['break_minutes']);
        $this->assertSame(420, $row['net_minutes']);
        $this->assertSame('Night', $row['shift_type']);
        $this->assertSame('Late', $row['notes']);
    }

    public function test_the_shift_of_an_anonymized_employee_keeps_a_name(): void
    {
        $employee = $this->employeeOf($this->storeA, ['name' => 'Soon gone']);
        $this->shift($employee, $this->storeA, '2026-11-10');
        $employee->delete();

        $this->assertNotSame('', $this->rows('shifts', $this->scope())[0]['employee']);
    }

    public function test_availabilities_export_the_declared_slots(): void
    {
        $anna = $this->employeeOf($this->storeA, ['name' => 'Anna']);
        $bob = $this->employeeOf($this->storeB, ['name' => 'Bob']);
        Availability::factory()->create(['user_id' => $anna->id, 'store_id' => $this->storeA->id, 'day_of_week' => 3, 'starts_at' => '10:00', 'ends_at' => '14:00', 'is_available' => true]);
        Availability::factory()->create(['user_id' => $anna->id, 'store_id' => $this->storeA->id, 'day_of_week' => 1, 'starts_at' => null, 'ends_at' => null, 'is_available' => false]);
        Availability::factory()->create(['user_id' => $bob->id, 'store_id' => $this->storeB->id]);

        $rows = $this->rows('availabilities', $this->scope([$this->storeA->id]));

        $this->assertSame([1, 3], array_column($rows, 'day_of_week'));
        $this->assertSame(['Anna', 'Anna'], array_column($rows, 'employee'));
        $this->assertFalse($rows[0]['available']);
        $this->assertSame('', $rows[0]['start_time']);
        $this->assertSame(['10:00', '14:00'], [$rows[1]['start_time'], $rows[1]['end_time']]);
        $this->assertSame([], $this->rows('availabilities', $this->scope([$this->storeA->id], [$bob->id])));
    }

    public function test_employees_export_contacts_only_for_members_never_secrets(): void
    {
        $anna = $this->employeeOf($this->storeA, ['first_name' => 'Anna', 'last_name' => 'Aaa', 'name' => 'Anna A', 'email' => 'anna@example.com']);
        EmployeeProfile::factory()->create(['user_id' => $anna->id, 'phone' => '03-1234', 'mobile_phone' => '090-1', 'postal_code' => '150-0001', 'address' => 'Shibuya 1', 'birth_date' => '1990-01-01', 'guarantor_name' => 'Secretive']);
        $this->storeB->members()->attach($anna);
        $this->employeeOf($this->storeB, ['last_name' => 'Zzz']);
        $this->employeeOf($this->storeA)->delete();

        $dataset = $this->dataset('employees');
        $rows = $this->rows('employees', $this->scope([$this->storeA->id, $this->storeB->id]));

        $this->assertSame(['Alpha', 'Beta', 'Beta'], array_column($rows, 'store'));
        $this->assertSame('anna@example.com', $rows[0]['email']);
        $this->assertSame('03-1234', $rows[0]['phone']);
        $this->assertSame('Shibuya 1', $rows[0]['address']);
        $this->assertTrue($rows[0]['active']);
        $encoded = json_encode($rows);
        foreach (['password', 'remember', 'two_factor', 'Secretive', '1990-01-01'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
        foreach ($dataset->columns() as $column) {
            $this->assertStringNotContainsString('password', $column->key);
        }
        $this->assertCount(1, $this->rows('employees', $this->scope([$this->storeA->id])));
    }

    public function test_an_inactive_membership_is_exported_as_inactive(): void
    {
        $anna = $this->employeeOf($this->storeA);
        $anna->stores()->updateExistingPivot($this->storeA->id, ['is_active' => false]);

        $this->assertFalse($this->rows('employees', $this->scope([$this->storeA->id]))[0]['active']);
    }

    public function test_shift_types_list_the_types_of_each_store_without_rates(): void
    {
        $day = ShiftType::factory()->create(['name' => 'Day', 'starts_at' => '08:00', 'ends_at' => '16:00', 'hourly_rate' => 99.99, 'sort_order' => 1]);
        $night = ShiftType::factory()->create(['name' => 'Night', 'starts_at' => '22:00', 'ends_at' => '06:00', 'sort_order' => 2, 'is_active' => false]);
        $this->storeA->shiftTypes()->attach([$day->id, $night->id]);
        $this->storeB->shiftTypes()->attach($day);

        $rows = $this->rows('shift_types', $this->scope([$this->storeA->id]));

        $this->assertSame(['Day', 'Night'], array_column($rows, 'name'));
        $this->assertSame(['08:00', '22:00'], array_column($rows, 'start_time'));
        $this->assertSame([true, false], array_column($rows, 'active'));
        $this->assertStringNotContainsString('99.99', json_encode($rows));
        $this->assertCount(3, $this->rows('shift_types', $this->scope()));
    }

    public function test_stores_dataset_lists_the_authorized_stores(): void
    {
        $rows = $this->rows('stores', $this->scope([$this->storeA->id]));

        $this->assertCount(1, $rows);
        $this->assertSame($this->storeA->id, $rows[0]['store_id']);
        $this->assertSame('Alpha', $rows[0]['name']);
        $this->assertSame('EUR', $rows[0]['currency']);
        $this->assertSame($this->storeA->code, $rows[0]['code']);
        $this->assertCount(2, $this->rows('stores', $this->scope()));
    }

    public function test_payroll_gives_net_minutes_rate_and_estimated_amount_per_shift(): void
    {
        $employee = $this->employeeOf($this->storeA, ['name' => 'Anna']);
        $type = ShiftType::factory()->create(['name' => 'Day', 'starts_at' => '08:00', 'ends_at' => '20:00', 'hourly_rate' => 10]);
        $this->storeA->shiftTypes()->attach($type);
        // 09:00-17:00 avec 60 min de pause : 7 h nettes au taux du type.
        $this->shift($employee, $this->storeA, '2026-11-10', '09:00', '17:00', ['shift_type_id' => $type->id]);
        // Taux personnel prioritaire.
        $this->shift($employee, $this->storeA, '2026-11-11');
        $employee->personalRates()->attach($type->id, ['hourly_rate' => 20]);
        // Surcharges manuelles du shift.
        $this->shift($employee, $this->storeA, '2026-11-12', '09:00', '17:00', ['hourly_rate_override' => 15, 'net_minutes_override' => 240]);
        $this->shift($this->employeeOf($this->storeB), $this->storeB, '2026-11-13');

        $rows = $this->rows('payroll', $this->scope([$this->storeA->id]));

        $this->assertSame(['2026-11-10', '2026-11-11', '2026-11-12'], array_column($rows, 'date'));
        $this->assertSame([420, 420, 240], array_column($rows, 'net_minutes'));
        $this->assertSame([20.0, 20.0, 15.0], array_column($rows, 'hourly_rate'));
        $this->assertSame([140.0, 140.0, 60.0], array_column($rows, 'amount'));
        $this->assertSame('EUR', $rows[0]['currency']);
        $this->assertSame('Anna', $rows[0]['employee']);
        $this->assertSame('Alpha', $rows[0]['store']);
    }

    public function test_payroll_reads_the_current_rates_on_every_export(): void
    {
        $employee = $this->employeeOf($this->storeA);
        $type = ShiftType::factory()->create(['starts_at' => '08:00', 'ends_at' => '20:00', 'hourly_rate' => 10]);
        $this->storeA->shiftTypes()->attach($type);
        $this->shift($employee, $this->storeA, '2026-11-10', '09:00', '17:00', ['pause_minutes' => 0]);

        $this->assertSame([80.0], array_column($this->rows('payroll', $this->scope()), 'amount'));

        $type->update(['hourly_rate' => 12]);

        $this->assertSame([96.0], array_column($this->rows('payroll', $this->scope()), 'amount'));
    }
}
