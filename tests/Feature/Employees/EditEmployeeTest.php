<?php

namespace Tests\Feature\Employees;

use App\Authorization\Authorizer;
use App\Events\EmployeeUpdated;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class EditEmployeeTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;

    private Store $storeA;

    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->storeA = Store::factory()->create();
        $this->storeB = Store::factory()->create();
    }

    public function test_manager_edits_identity_and_hr_data_and_event_is_emitted(): void
    {
        Event::fake([EmployeeUpdated::class]);
        $employee = $this->employeeOf($this->storeA, ['email' => 'old@example.com']);
        $this->actingAs($this->managerOf($this->storeA));

        Livewire::test('pages::employees.edit', ['user' => $employee])
            ->assertSet('email', 'old@example.com')
            ->set('first_name', 'Nouveau')
            ->set('last_name', 'Nom')
            ->set('furigana_first_name', 'ヌーボー')
            ->set('furigana_last_name', 'ノム')
            ->set('name', 'Nom Nouveau')
            ->set('email', 'new@example.com')
            ->set('employee_code', 'n-1')
            ->set('hr.gender', 'female')
            ->set('hr.birth_date', '1990-05-17')
            ->set('hr.phone', '03-0000-0000')
            ->set('hr.guarantor_name', 'Parent Nom')
            ->call('save')
            ->assertHasNoErrors();

        $employee->refresh();
        $this->assertSame('Nouveau', $employee->first_name);
        $this->assertSame('new@example.com', $employee->email);
        $this->assertSame('N-1', $employee->employee_code);
        $this->assertSame('female', $employee->profile->gender);
        $this->assertSame('1990-05-17', $employee->profile->birth_date->format('Y-m-d'));
        $this->assertSame('03-0000-0000', $employee->profile->phone);
        $this->assertSame('Parent Nom', $employee->profile->guarantor_name);
        Event::assertDispatched(EmployeeUpdated::class, fn (EmployeeUpdated $e): bool => $e->userId === $employee->id);
    }

    public function test_email_can_be_cleared_and_must_stay_unique(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $employee = $this->employeeOf($this->storeA, ['email' => 'mine@example.com']);
        $this->actingAs($this->managerOf($this->storeA));

        Livewire::test('pages::employees.edit', ['user' => $employee])
            ->set('email', 'taken@example.com')
            ->assertHasErrors('email')
            ->set('email', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($employee->fresh()->email);
    }

    public function test_manager_cannot_open_or_modify_an_owner(): void
    {
        $owner = $this->owner();
        $this->storeA->members()->attach($owner);
        $this->actingAs($this->managerOf($this->storeA));

        $this->get(route('employees.edit', $owner))->assertForbidden();
        Livewire::test('pages::employees.edit', ['user' => $owner])->assertForbidden();
    }

    public function test_manager_cannot_open_an_employee_of_another_store(): void
    {
        $this->actingAs($this->managerOf($this->storeA));

        $this->get(route('employees.edit', $this->employeeOf($this->storeB)))->assertForbidden();
    }

    public function test_save_rechecks_authorization_when_rights_are_lost_after_mount(): void
    {
        $employee = $this->employeeOf($this->storeA);
        $manager = $this->managerOf($this->storeA);
        $this->actingAs($manager);

        $component = Livewire::test('pages::employees.edit', ['user' => $employee]);

        $manager->removeRole($this->role('manager'), $this->storeA);
        $employee->stores()->detach();

        Event::fake([EmployeeUpdated::class]);

        $component->call('save')->assertForbidden();
        Event::assertNotDispatched(EmployeeUpdated::class);
    }

    public function test_view_only_actor_can_read_but_not_save(): void
    {
        $employee = $this->employeeOf($this->storeA);
        $viewer = User::factory()->create();
        $viewer->assignRole($this->customRole(['employees.view']), $this->storeA);
        $this->actingAs($viewer);

        Livewire::test('pages::employees.edit', ['user' => $employee])
            ->assertOk()
            ->assertDontSee('data-test="save-employee-button"', false)
            ->set('first_name', 'Hacked')
            ->call('save')
            ->assertForbidden();
    }

    public function test_owner_cannot_deactivate_themselves(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);

        Livewire::test('pages::employees.edit', ['user' => $owner])
            ->set('is_active', false)
            ->set('last_name', 'X')
            ->call('save')
            ->assertHasErrors('is_active');

        $this->assertTrue($owner->fresh()->is_active);
    }

    public function test_owner_cannot_remove_their_own_owner_role(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);

        Livewire::test('pages::employees.edit', ['user' => $owner])
            ->set('is_owner', false)
            ->call('save')
            ->assertHasErrors('is_owner');

        $this->assertTrue(app(Authorizer::class)->isOwner($owner->fresh()));
    }

    public function test_owner_can_deactivate_another_owner_while_one_remains(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $this->actingAs($owner);

        Livewire::test('pages::employees.edit', ['user' => $other])
            ->set('is_active', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse($other->fresh()->is_active);
    }

    public function test_non_owner_cannot_deactivate_themselves(): void
    {
        $manager = $this->managerOf($this->storeA);
        $this->storeA->members()->attach($manager);
        $this->actingAs($manager);

        Livewire::test('pages::employees.edit', ['user' => $manager])
            ->set('is_active', false)
            ->call('save')
            ->assertHasErrors('is_active');
    }

    public function test_manager_can_deactivate_an_employee(): void
    {
        $employee = $this->employeeOf($this->storeA);
        $this->actingAs($this->managerOf($this->storeA));

        Livewire::test('pages::employees.edit', ['user' => $employee])
            ->set('is_active', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse($employee->fresh()->is_active);
    }

    public function test_owner_checkbox_can_only_be_changed_by_an_owner(): void
    {
        $employee = $this->employeeOf($this->storeA);

        $this->actingAs($this->managerOf($this->storeA));
        Livewire::test('pages::employees.edit', ['user' => $employee])
            ->set('is_owner', true)
            ->call('save')
            ->assertForbidden();
        $this->assertFalse(app(Authorizer::class)->isOwner($employee->fresh()));

        $this->actingAs($this->owner());
        Livewire::test('pages::employees.edit', ['user' => $employee])
            ->set('is_owner', true)
            ->call('save')
            ->assertHasNoErrors();
        $this->assertTrue(app(Authorizer::class)->isOwner($employee->fresh()));
    }

    public function test_non_owner_cannot_change_their_own_store_role(): void
    {
        $manager = $this->managerOf($this->storeA);
        $this->actingAs($manager);

        Livewire::test('pages::employees.edit', ['user' => $manager])
            ->set('storeRoles.'.$this->storeA->id, (string) $this->role('employee')->id)
            ->call('save')
            ->assertHasErrors('store_roles.'.$this->storeA->id);

        $this->assertSame(['manager'], $manager->roles()->pluck('slug')->all());
    }

    public function test_store_role_is_replaced_not_added(): void
    {
        $employee = $this->employeeOf($this->storeA);
        $employee->assignRole($this->customRole([]), $this->storeA);
        $this->assertSame(2, $employee->roles()->count());
        $this->actingAs($this->managerOf($this->storeA));

        Livewire::test('pages::employees.edit', ['user' => $employee])
            ->set('storeRoles.'.$this->storeA->id, (string) $this->role('manager')->id)
            ->call('save')
            ->assertHasNoErrors();

        $roles = $employee->roles()->get();
        $this->assertSame(['manager'], $roles->pluck('slug')->all());
        $this->assertSame($this->storeA->id, $roles->first()->pivot->store_id);
    }

    public function test_role_above_the_actor_ceiling_is_refused(): void
    {
        $employee = $this->employeeOf($this->storeA);
        $powerful = $this->customRole(['stores.create']);
        $this->actingAs($this->managerOf($this->storeA));

        Livewire::test('pages::employees.edit', ['user' => $employee])
            ->set('storeRoles.'.$this->storeA->id, (string) $powerful->id)
            ->call('save')
            ->assertHasErrors('store_roles.'.$this->storeA->id);

        $this->assertSame(['employee'], $employee->roles()->pluck('slug')->all());
    }

    public function test_system_role_cannot_be_set_as_store_role(): void
    {
        $employee = $this->employeeOf($this->storeA);
        $this->actingAs($this->owner());

        Livewire::test('pages::employees.edit', ['user' => $employee])
            ->set('storeRoles.'.$this->storeA->id, (string) $this->role('owner')->id)
            ->call('save')
            ->assertHasErrors('store_roles.'.$this->storeA->id);
    }

    public function test_roles_are_only_editable_for_stores_the_actor_manages(): void
    {
        $employee = $this->employeeOf($this->storeA);
        $employee->stores()->attach($this->storeB);
        $employee->assignRole($this->role('employee'), $this->storeB);
        $this->actingAs($this->managerOf($this->storeA));

        $component = Livewire::test('pages::employees.edit', ['user' => $employee]);
        $this->assertSame([(string) $this->storeA->id], array_map('strval', array_keys($component->get('storeRoles'))));

        $component
            ->set('storeRoles.'.$this->storeB->id, (string) $this->role('manager')->id)
            ->call('save')
            ->assertHasErrors('store_roles.'.$this->storeB->id);

        $this->assertSame(['employee', 'employee'], $employee->roles()->pluck('slug')->all());
    }

    public function test_unchanged_role_selection_does_not_require_the_ceiling(): void
    {
        $employee = $this->employeeOf($this->storeA);
        $employee->removeRole($this->role('employee'), $this->storeA);
        $strong = $this->customRole(['stores.create']);
        $employee->assignRole($strong, $this->storeA);
        $this->actingAs($this->managerOf($this->storeA));

        Livewire::test('pages::employees.edit', ['user' => $employee])
            ->assertSet('storeRoles.'.$this->storeA->id, (string) $strong->id)
            ->set('first_name', 'Renamed')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($strong->id, $employee->roles()->first()->id);
    }
}
