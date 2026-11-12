<?php

namespace Tests\Feature\Exports;

use App\Exports\AbstractExportDataset;
use App\Exports\ExportAccess;
use App\Models\Store;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Kintai\Contracts\Exports\ExportColumn;
use Kintai\Contracts\Exports\ExportDataset;
use Kintai\Contracts\Exports\ExportDatasetRegistry;
use Kintai\Contracts\Exports\ExportScope;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class ExportAccessTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;

    private Store $storeA;

    private Store $storeB;

    private ExportAccess $access;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->storeA = Store::factory()->create(['name' => 'Alpha']);
        $this->storeB = Store::factory()->create(['name' => 'Beta']);
        $this->access = app(ExportAccess::class);
    }

    private function dataset(string $key): ExportDataset
    {
        return app(ExportDatasetRegistry::class)->find($key) ?? $this->fail("Unknown dataset {$key}");
    }

    public function test_the_core_registers_its_datasets_and_refuses_duplicates(): void
    {
        $registry = app(ExportDatasetRegistry::class);

        $this->assertSame(['shifts', 'employees', 'availabilities', 'shift_types', 'stores', 'payroll'], array_keys($registry->all()));
        $this->assertNull($registry->find('nope'));

        $this->expectException(InvalidArgumentException::class);
        $registry->register($this->dataset('shifts'));
    }

    public function test_a_bundle_dataset_registered_in_the_registry_is_offered_under_its_own_permission(): void
    {
        app(ExportDatasetRegistry::class)->register(new class extends AbstractExportDataset
        {
            public function key(): string
            {
                return 'timeoff';
            }

            public function label(): string
            {
                return 'Time off';
            }

            public function permission(): string
            {
                return 'timeoff.view';
            }

            public function columns(): array
            {
                return [new ExportColumn('id', 'Id', ExportColumn::INTEGER)];
            }

            protected function query(ExportScope $scope): Builder
            {
                return Store::query()->whereIn('id', $scope->storeIds);
            }

            protected function row(mixed $record): array
            {
                return ['id' => $record->id];
            }
        });
        $allowed = User::factory()->create();
        $allowed->assignRole($this->customRole(['timeoff.view']), $this->storeA);

        $this->assertArrayHasKey('timeoff', $this->access->available($allowed));
        $this->assertSame([$this->storeA->id], $this->access->available($allowed)['timeoff']['stores']);
        $this->assertArrayNotHasKey('timeoff', $this->access->available($this->employeeOf($this->storeA)));
    }

    public function test_everyone_can_at_least_export_their_own_data(): void
    {
        $employee = $this->employeeOf($this->storeA);

        $available = $this->access->available($employee);

        $this->assertSame(['shifts', 'employees', 'availabilities'], array_keys($available));
        foreach ($available as $option) {
            $this->assertSame([], $option['stores']);
            $this->assertTrue($option['self']);
        }
    }

    public function test_a_user_without_store_or_rights_has_nothing_to_export(): void
    {
        $this->assertSame([], $this->access->available(User::factory()->create()));
    }

    public function test_a_manager_is_limited_to_their_stores_and_gets_the_payroll_export(): void
    {
        $manager = $this->managerOf($this->storeA);

        $available = $this->access->available($manager);

        $this->assertSame([$this->storeA->id], $available['shifts']['stores']);
        $this->assertSame([$this->storeA->id], $available['payroll']['stores']);
        $this->assertFalse($available['shifts']['self']);
    }

    public function test_the_permission_is_checked_per_dataset(): void
    {
        $user = User::factory()->create();
        $user->assignRole($this->customRole(['shifts.view']), $this->storeA);

        $available = $this->access->available($user);

        $this->assertArrayHasKey('shifts', $available);
        $this->assertArrayHasKey('shift_types', $available);
        $this->assertArrayNotHasKey('payroll', $available);
        $this->assertArrayNotHasKey('employees', $available);
        $this->assertArrayNotHasKey('stores', $available);
    }

    public function test_payroll_requires_payroll_export_and_never_self_service(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole($this->customRole(['payroll.view', 'shifts.view']), $this->storeA);
        $exporter = User::factory()->create();
        $exporter->assignRole($this->customRole(['payroll.export']), $this->storeA);

        $this->assertArrayNotHasKey('payroll', $this->access->available($viewer));
        $this->assertSame([$this->storeA->id], $this->access->available($exporter)['payroll']['stores']);

        $this->expectException(AuthorizationException::class);
        $this->access->scope($this->employeeOf($this->storeA), $this->dataset('payroll'), true, [], [], '2026-11-01', '2026-11-30');
    }

    public function test_an_owner_sees_every_active_store(): void
    {
        Store::factory()->inactive()->create();

        $this->assertSame([$this->storeA->id, $this->storeB->id], $this->access->available($this->owner())['shifts']['stores']);
    }

    public function test_my_data_scope_is_the_actor_in_their_own_stores(): void
    {
        $employee = $this->employeeOf($this->storeA);
        $this->storeB->members()->attach($employee);

        $scope = $this->access->scope($employee, $this->dataset('shifts'), true, [$this->storeB->id], [999], '2026-11-01', '2026-11-30');

        $this->assertTrue($scope->self);
        $this->assertEqualsCanonicalizing([$this->storeA->id, $this->storeB->id], $scope->storeIds);
        $this->assertSame([$employee->id], $scope->userIds);
    }

    public function test_a_manager_cannot_export_a_store_they_do_not_manage(): void
    {
        $manager = $this->managerOf($this->storeA);

        $this->expectException(AuthorizationException::class);

        $this->access->scope($manager, $this->dataset('shifts'), false, [$this->storeA->id, $this->storeB->id], [], '2026-11-01', '2026-11-30');
    }

    public function test_no_store_means_all_permitted_stores(): void
    {
        $scope = $this->access->scope($this->managerOf($this->storeA), $this->dataset('shifts'), false, [], [], '2026-11-01', '2026-11-30');

        $this->assertSame([$this->storeA->id], $scope->storeIds);
        $this->assertNull($scope->userIds);
    }

    public function test_an_employee_without_rights_cannot_export_other_people(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->access->scope($this->employeeOf($this->storeA), $this->dataset('shifts'), false, [$this->storeA->id], [], '2026-11-01', '2026-11-30');
    }

    public function test_requested_employees_are_cut_down_to_the_members_of_the_exported_stores(): void
    {
        $manager = $this->managerOf($this->storeA);
        $mine = $this->employeeOf($this->storeA);
        $stranger = $this->employeeOf($this->storeB);

        $scope = $this->access->scope($manager, $this->dataset('shifts'), false, [$this->storeA->id], [$mine->id, $stranger->id], '2026-11-01', '2026-11-30');
        $onlyStranger = $this->access->scope($manager, $this->dataset('shifts'), false, [$this->storeA->id], [$stranger->id], '2026-11-01', '2026-11-30');

        $this->assertSame([$mine->id], $scope->userIds);
        // Aucun employé valable : un export vide, jamais tous les membres.
        $this->assertSame([], $onlyStranger->userIds);
    }

    public function test_the_listed_employees_are_the_ones_the_actor_can_see(): void
    {
        $manager = $this->managerOf($this->storeA);
        $mine = $this->employeeOf($this->storeA, ['name' => 'Mine']);
        $this->employeeOf($this->storeB, ['name' => 'Stranger']);
        $owner = $this->owner();
        $this->storeA->members()->attach($owner);

        $asManager = $this->access->employees($manager, [$this->storeA->id]);
        $asOwner = $this->access->employees($owner, [$this->storeA->id]);

        $this->assertSame(['Mine'], array_column($asManager, 'name'));
        $this->assertContains($owner->name, array_column($asOwner, 'name'));
        $this->assertContains($mine->name, array_column($asOwner, 'name'));
        // Le périmètre exclut aussi l'Owner, même sans employé coché.
        $scope = $this->access->scope($manager, $this->dataset('shifts'), false, [$this->storeA->id], [], '2026-11-01', '2026-11-30');
        $this->assertSame([$owner->id], $scope->excludedUserIds);
        $this->assertSame([], $this->access->scope($owner, $this->dataset('shifts'), false, [], [], '2026-11-01', '2026-11-30')->excludedUserIds);
    }

    public function test_datasets_without_employees_ignore_the_employee_filter(): void
    {
        $scope = $this->access->scope($this->managerOf($this->storeA), $this->dataset('shift_types'), false, [], [123], null, null);

        $this->assertNull($scope->userIds);
    }

    public function test_the_period_is_validated_only_for_datasets_that_have_one(): void
    {
        $manager = $this->managerOf($this->storeA);

        $this->access->scope($manager, $this->dataset('stores'), false, [], [], null, null);

        foreach ([[null, null], ['2026-11-30', '2026-11-01'], ['2026-02-30', '2026-03-01'], ['2026-01-01', '2027-02-01'], ['bad', '2026-11-01']] as [$from, $to]) {
            try {
                $this->access->scope($manager, $this->dataset('shifts'), false, [], [], $from, $to);
                $this->fail("Period {$from} - {$to} was accepted.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('from', $e->errors());
            }
        }

        $this->assertSame('2026-11-30', $this->access->scope($manager, $this->dataset('shifts'), false, [], [], '2026-11-01', '2026-11-30')->to->toDateString());
    }
}
