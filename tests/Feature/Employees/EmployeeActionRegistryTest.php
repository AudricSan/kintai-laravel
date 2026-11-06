<?php

namespace Tests\Feature\Employees;

use App\Models\Store;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Kintai\Contracts\Employees\EmployeeAction;
use Kintai\Contracts\Employees\EmployeeActionRegistry;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

/**
 * Contrat `EmployeeActionRegistry` : visibilité par personne et par employé, ordre, doublons, affichage sur la liste et la fiche.
 */
class EmployeeActionRegistryTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->store = Store::factory()->create();
    }

    private function registry(): EmployeeActionRegistry
    {
        return app(EmployeeActionRegistry::class);
    }

    private function action(string $key, int $order = 100, ?callable $visible = null): EmployeeAction
    {
        return new EmployeeAction($key, 'Action '.$key, 'employees.create', $order, $visible ?? fn (): bool => true, 'user-minus');
    }

    public function test_without_any_registered_action_nothing_is_returned(): void
    {
        $this->assertSame([], $this->registry()->actionsFor($this->owner(), 1));
    }

    public function test_actions_are_filtered_by_viewer_and_employee_and_sorted_by_order(): void
    {
        $viewer = $this->owner();
        $employee = $this->employeeOf($this->store);
        $this->registry()->register($this->action('late', 20));
        $this->registry()->register($this->action('early', 10));
        $this->registry()->register($this->action('hidden', 5, fn (Authenticatable $viewer, int $id): bool => false));
        $this->registry()->register($this->action('only-other', 1, fn (Authenticatable $viewer, int $id): bool => $id !== $employee->id));

        $this->assertTrue($this->registry()->has('late'));
        $this->assertSame(['early', 'late'], array_map(fn (EmployeeAction $a): string => $a->key, $this->registry()->actionsFor($viewer, $employee->id)));
        $this->assertSame(route('employees.create', ['user' => 7]), $this->registry()->actionsFor($viewer, $employee->id)[0]->url(7));
    }

    public function test_a_duplicate_key_is_refused(): void
    {
        $this->registry()->register($this->action('same'));

        $this->expectException(InvalidArgumentException::class);
        $this->registry()->register($this->action('same'));
    }

    public function test_actions_show_on_the_list_and_the_employee_page_with_the_employee_in_the_query(): void
    {
        $manager = $this->managerOf($this->store);
        $this->store->members()->attach($manager);
        $employee = $this->employeeOf($this->store, ['name' => 'Target Person']);
        $this->registry()->register($this->action('dismiss'));
        $this->registry()->register($this->action('never', 1, fn (): bool => false));

        $this->actingAs($manager);

        Livewire::test('pages::employees.index')
            ->assertSeeHtml('data-test="employee-action-dismiss"')
            ->assertSeeHtml('?user='.$employee->id)
            ->assertDontSeeHtml('data-test="employee-action-never"');
        Livewire::test('pages::employees.edit', ['user' => $employee])
            ->assertSeeHtml('data-test="employee-action-dismiss"')
            ->assertSeeHtml('?user='.$employee->id);
    }

    public function test_the_list_without_actions_still_renders(): void
    {
        $manager = $this->managerOf($this->store);
        $this->store->members()->attach($manager);
        $this->actingAs($manager);

        Livewire::test('pages::employees.index')->assertDontSeeHtml('data-test="employee-action-');
    }

    public function test_a_viewer_closure_receives_the_authenticated_user(): void
    {
        $seen = null;
        $this->registry()->register($this->action('probe', 1, function (Authenticatable $viewer) use (&$seen): bool {
            $seen = $viewer;

            return true;
        }));
        $manager = $this->managerOf($this->store);

        $this->registry()->actionsFor($manager, 1);

        $this->assertInstanceOf(User::class, $seen);
        $this->assertSame($manager->id, $seen->id);
    }
}
