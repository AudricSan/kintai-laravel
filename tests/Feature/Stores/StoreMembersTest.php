<?php

namespace Tests\Feature\Stores;

use App\Actions\Stores\AddStoreMember;
use App\Actions\Stores\RemoveStoreMember;
use App\Actions\Stores\UpdateStoreMember;
use App\Authorization\Authorizer;
use App\Events\StoreMemberAdded;
use App\Events\StoreMemberRemoved;
use App\Events\StoreMemberUpdated;
use App\Models\RoleAssignment;
use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class StoreMembersTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;

    private Store $storeA;

    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->storeA = Store::factory()->create(['name' => 'Alpha']);
        $this->storeB = Store::factory()->create(['name' => 'Bravo']);
    }

    private function roleIds(User $user, Store $store): array
    {
        return RoleAssignment::where('user_id', $user->id)->where('store_id', $store->id)->pluck('role_id')->all();
    }

    public function test_manager_adds_a_member_with_the_default_employee_role(): void
    {
        Event::fake([StoreMemberAdded::class]);
        $manager = $this->managerOf($this->storeA);
        $newcomer = User::factory()->create(['name' => 'Newcomer Person']);
        $this->actingAs($manager);

        Livewire::test('pages::stores.form', ['store' => $this->storeA])
            ->assertSet('add.role_id', (string) $this->role('employee')->id)
            ->set('candidateSearch', 'Newcomer')
            ->assertSee('Newcomer Person')
            ->set('add.user_id', (string) $newcomer->id)
            ->set('add.staff_code', 'S-01')
            ->set('add.hire_date', '2026-01-15')
            ->set('add.contract_type', 'Part-time')
            ->call('addMember')
            ->assertHasNoErrors()
            ->assertSet('add.user_id', '');

        $membership = $this->storeA->members()->where('users.id', $newcomer->id)->firstOrFail()->pivot;
        $this->assertSame('S-01', $membership->staff_code);
        $this->assertSame('2026-01-15', $membership->hire_date->format('Y-m-d'));
        $this->assertSame('Part-time', $membership->contract_type);
        $this->assertTrue($membership->is_active);
        $this->assertSame([$this->role('employee')->id], $this->roleIds($newcomer, $this->storeA));
        Event::assertDispatched(StoreMemberAdded::class, fn (StoreMemberAdded $e): bool => $e->storeId === $this->storeA->id && $e->userId === $newcomer->id);
    }

    public function test_adding_replaces_a_stale_role_of_the_store_and_keeps_other_stores(): void
    {
        $user = User::factory()->create();
        $user->assignRole($this->role('manager'), $this->storeA);
        $user->assignRole($this->role('employee'), $this->storeB);

        app(AddStoreMember::class)->handle($this->owner(), $this->storeA, ['user_id' => $user->id]);

        $this->assertSame([$this->role('employee')->id], $this->roleIds($user, $this->storeA));
        $this->assertSame([$this->role('employee')->id], $this->roleIds($user, $this->storeB));
    }

    public function test_role_ceiling_and_system_role_are_refused_when_adding(): void
    {
        $manager = $this->managerOf($this->storeA);
        $rich = $this->customRole(['payroll.generate', 'employees.update']);
        $newcomer = User::factory()->create();

        try {
            app(AddStoreMember::class)->handle($manager, $this->storeA, ['user_id' => $newcomer->id, 'role_id' => $this->customRole(['stores.create'])->id]);
            $this->fail('The ceiling should be enforced.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('role_id', $e->errors());
        }

        try {
            app(AddStoreMember::class)->handle($this->owner(), $this->storeA, ['user_id' => $newcomer->id, 'role_id' => $this->role('owner')->id]);
            $this->fail('A system role should be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('role_id', $e->errors());
        }

        // Un rôle dans les droits de l'acteur passe.
        app(AddStoreMember::class)->handle($manager, $this->storeA, ['user_id' => $newcomer->id, 'role_id' => $rich->id]);
        $this->assertSame([$rich->id], $this->roleIds($newcomer, $this->storeA));
    }

    public function test_cannot_add_an_inactive_user_or_an_existing_member(): void
    {
        $manager = $this->managerOf($this->storeA);
        $inactive = User::factory()->create(['is_active' => false]);
        $member = $this->employeeOf($this->storeA);

        foreach ([$inactive, $member] as $user) {
            try {
                app(AddStoreMember::class)->handle($manager, $this->storeA, ['user_id' => $user->id]);
                $this->fail('Should be refused.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('user_id', $e->errors());
            }
        }
    }

    public function test_non_owner_cannot_add_update_or_remove_an_owner(): void
    {
        $manager = $this->managerOf($this->storeA);
        $owner = $this->owner();

        $attempts = [
            fn () => app(AddStoreMember::class)->handle($manager, $this->storeA, ['user_id' => $owner->id]),
            fn () => app(UpdateStoreMember::class)->handle($manager, $this->storeA, $owner, ['is_active' => true]),
            fn () => app(RemoveStoreMember::class)->handle($manager, $this->storeA, $owner),
        ];

        foreach ($attempts as $index => $attempt) {
            // Le propriétaire doit être membre pour que la modification et le retrait aient une cible.
            if ($index === 1) {
                $this->storeA->members()->attach($owner);
            }

            try {
                $attempt();
                $this->fail('An Owner must be protected (attempt '.$index.').');
            } catch (AuthorizationException) {
                $this->assertSame($index > 0, $this->storeA->members()->where('users.id', $owner->id)->exists());
            }
        }
    }

    public function test_owner_can_add_another_owner_as_member(): void
    {
        $other = $this->owner();

        app(AddStoreMember::class)->handle($this->owner(), $this->storeA, ['user_id' => $other->id]);

        $this->assertTrue($this->storeA->members()->where('users.id', $other->id)->exists());
    }

    public function test_manager_updates_a_member_and_changes_the_role(): void
    {
        Event::fake([StoreMemberUpdated::class]);
        $manager = $this->managerOf($this->storeA);
        $member = $this->employeeOf($this->storeA);
        $helper = $this->customRole(['shifts.view']);
        $this->actingAs($manager);

        Livewire::test('pages::stores.form', ['store' => $this->storeA])
            ->call('editMember', $member->id)
            ->assertSet('member.role_id', (string) $this->role('employee')->id)
            ->set('member.staff_code', 'E-7')
            ->set('member.hire_date', '2025-04-01')
            ->set('member.contract_type', 'Full-time')
            ->set('member.is_active', false)
            ->set('member.role_id', (string) $helper->id)
            ->call('saveMember')
            ->assertHasNoErrors();

        $membership = $this->storeA->members()->where('users.id', $member->id)->firstOrFail()->pivot;
        $this->assertSame('E-7', $membership->staff_code);
        $this->assertSame('Full-time', $membership->contract_type);
        $this->assertFalse($membership->is_active);
        $this->assertSame([$helper->id], $this->roleIds($member, $this->storeA));
        Event::assertDispatched(StoreMemberUpdated::class, fn (StoreMemberUpdated $e): bool => $e->userId === $member->id);
    }

    public function test_unchanged_role_does_not_require_the_ceiling(): void
    {
        $manager = $this->managerOf($this->storeA);
        $above = $this->customRole(['stores.create']);
        $member = User::factory()->create();
        $this->storeA->members()->attach($member);
        $member->assignRole($above, $this->storeA);

        app(UpdateStoreMember::class)->handle($manager, $this->storeA, $member, ['staff_code' => 'X1', 'is_active' => true, 'role_id' => $above->id]);

        $this->assertSame([$above->id], $this->roleIds($member, $this->storeA));

        // Changer pour un autre rôle au-dessus du plafond reste refusé.
        $this->expectException(ValidationException::class);
        app(UpdateStoreMember::class)->handle($manager, $this->storeA, $member, ['is_active' => true, 'role_id' => $this->customRole(['stores.delete'])->id]);
    }

    public function test_manager_cannot_change_their_own_role_or_deactivate_or_remove_themselves(): void
    {
        $manager = $this->managerOf($this->storeA);
        $this->storeA->members()->attach($manager);
        $update = app(UpdateStoreMember::class);

        try {
            $update->handle($manager, $this->storeA, $manager, ['is_active' => true, 'role_id' => (string) $this->role('employee')->id]);
            $this->fail('Own role change should be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('role_id', $e->errors());
        }

        try {
            $update->handle($manager, $this->storeA, $manager, ['is_active' => false]);
            $this->fail('Self deactivation should be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('is_active', $e->errors());
        }

        try {
            app(RemoveStoreMember::class)->handle($manager, $this->storeA, $manager);
            $this->fail('Self removal should be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('member', $e->errors());
        }

        // Modifier ses propres données sans toucher au rôle reste possible.
        $update->handle($manager, $this->storeA, $manager, ['is_active' => true, 'staff_code' => 'ME']);
        $this->assertSame('ME', $this->storeA->members()->where('users.id', $manager->id)->first()->pivot->staff_code);
    }

    public function test_removal_deletes_the_roles_of_the_store_only_and_keeps_shifts(): void
    {
        Event::fake([StoreMemberRemoved::class]);
        $manager = $this->managerOf($this->storeA);
        $member = $this->employeeOf($this->storeA);
        $this->storeB->members()->attach($member);
        $member->assignRole($this->role('employee'), $this->storeB);
        $shift = Shift::factory()->create(['store_id' => $this->storeA->id, 'user_id' => $member->id]);
        $this->actingAs($manager);

        Livewire::test('pages::stores.form', ['store' => $this->storeA])
            ->call('confirmRemoval', $member->id)
            ->call('removeMember')
            ->assertHasNoErrors();

        $this->assertFalse($this->storeA->members()->where('users.id', $member->id)->exists());
        $this->assertSame([], $this->roleIds($member, $this->storeA));
        $this->assertSame([$this->role('employee')->id], $this->roleIds($member, $this->storeB));
        $this->assertTrue($this->storeB->members()->where('users.id', $member->id)->exists());
        $this->assertModelExists($shift);
        $this->assertFalse(app(Authorizer::class)->allows($member, 'employees.view', $this->storeA));
        Event::assertDispatched(StoreMemberRemoved::class, fn (StoreMemberRemoved $e): bool => $e->storeId === $this->storeA->id && $e->userId === $member->id);
    }

    public function test_staff_code_is_unique_per_store(): void
    {
        $manager = $this->managerOf($this->storeA);
        $first = $this->employeeOf($this->storeA);
        $second = $this->employeeOf($this->storeA);
        $inOtherStore = $this->employeeOf($this->storeB);
        $update = app(UpdateStoreMember::class);

        $update->handle($manager, $this->storeA, $first, ['is_active' => true, 'staff_code' => 'DUP']);

        try {
            $update->handle($manager, $this->storeA, $second, ['is_active' => true, 'staff_code' => 'DUP']);
            $this->fail('Duplicate staff code should be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('staff_code', $e->errors());
        }

        // Le même code est permis dans un autre magasin, et un membre peut garder le sien.
        $update->handle($this->owner(), $this->storeB, $inOtherStore, ['is_active' => true, 'staff_code' => 'DUP']);
        $update->handle($manager, $this->storeA, $first, ['is_active' => true, 'staff_code' => 'DUP']);

        $this->expectException(ValidationException::class);
        app(AddStoreMember::class)->handle($manager, $this->storeA, ['user_id' => User::factory()->create()->id, 'staff_code' => 'DUP']);
    }

    public function test_members_section_requires_employees_update_on_the_store(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole($this->customRole(['stores.view', 'stores.update']), $this->storeA);
        $member = $this->employeeOf($this->storeA);
        $this->actingAs($viewer);

        Livewire::test('pages::stores.form', ['store' => $this->storeA])
            ->assertOk()
            ->assertDontSee('data-test="members-section"', false)
            ->call('confirmRemoval', $member->id)
            ->call('removeMember')
            ->assertForbidden();

        $this->assertTrue($this->storeA->members()->where('users.id', $member->id)->exists());

        $this->expectException(AuthorizationException::class);
        app(AddStoreMember::class)->handle($viewer, $this->storeA, ['user_id' => User::factory()->create()->id]);
    }

    public function test_manager_of_another_store_cannot_manage_members(): void
    {
        $outsider = $this->managerOf($this->storeB);
        $member = $this->employeeOf($this->storeA);

        $this->expectException(AuthorizationException::class);
        app(RemoveStoreMember::class)->handle($outsider, $this->storeA, $member);
    }

    public function test_member_actions_recheck_authorization_when_rights_are_lost_after_mount(): void
    {
        $manager = $this->managerOf($this->storeA);
        $member = $this->employeeOf($this->storeA);
        $this->actingAs($manager);

        $component = Livewire::test('pages::stores.form', ['store' => $this->storeA])
            ->call('editMember', $member->id);

        $manager->removeRole($this->role('manager'), $this->storeA);

        Event::fake([StoreMemberUpdated::class]);
        $component->call('saveMember')->assertForbidden();
        Event::assertNotDispatched(StoreMemberUpdated::class);
    }

    public function test_employee_page_links_to_accessible_stores(): void
    {
        $manager = $this->managerOf($this->storeA);
        $employee = $this->employeeOf($this->storeA);
        $this->storeB->members()->attach($employee);
        $this->actingAs($manager);

        Livewire::test('pages::employees.edit', ['user' => $employee])
            ->assertSee(route('stores.edit', $this->storeA), false)
            ->assertDontSee(route('stores.edit', $this->storeB), false);
    }
}
