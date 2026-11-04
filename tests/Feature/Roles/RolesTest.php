<?php

namespace Tests\Feature\Roles;

use App\Authorization\Authorizer;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class RolesTest extends TestCase
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

    public function test_non_owner_is_forbidden_everywhere(): void
    {
        $role = $this->customRole(['shifts.view']);
        $manager = $this->managerOf($this->store);
        $this->actingAs($manager);

        $this->get(route('roles.index'))->assertForbidden();
        $this->get(route('roles.create'))->assertForbidden();
        $this->get(route('roles.edit', $role))->assertForbidden();
        Livewire::test('pages::roles.index')->assertForbidden();
        Livewire::test('pages::roles.form')->assertForbidden();
    }

    public function test_non_owner_cannot_run_actions_even_if_the_component_was_mounted_by_an_owner(): void
    {
        $role = $this->customRole(['shifts.view']);
        $this->actingAs($this->owner());
        $index = Livewire::test('pages::roles.index');
        $form = Livewire::test('pages::roles.form', ['role' => $role]);

        $form->set('name', 'Hacked');

        $this->actingAs($this->managerOf($this->store));

        $index->call('delete', $role->id)->assertForbidden();
        $form->call('save')->assertForbidden();
        $this->assertNotSame('Hacked', $role->fresh()->name);
        $this->assertNotNull($role->fresh());
    }

    public function test_owner_sees_the_list_with_holder_counts_and_system_mark(): void
    {
        $role = $this->customRole([]);
        $this->employeeOf($this->store)->assignRole($role, $this->store);
        $this->actingAs($this->owner());

        Livewire::test('pages::roles.index')
            ->assertOk()
            ->assertSee($role->name)
            ->assertSee('System')
            ->assertSee('Owner');
    }

    public function test_role_creation_generates_a_unique_slug(): void
    {
        $this->actingAs($this->owner());

        foreach (['Chef de rayon', 'Chef de rayon', 'Chef de rayon'] as $name) {
            Livewire::test('pages::roles.form')->set('name', $name)->call('save')->assertHasNoErrors();
        }
        Livewire::test('pages::roles.form')->set('name', 'Équipe été')->call('save');
        Livewire::test('pages::roles.form')->set('name', '!!!')->call('save');

        $this->assertSame(
            ['chef-de-rayon', 'chef-de-rayon-2', 'chef-de-rayon-3', 'equipe-ete', 'role'],
            Role::query()->whereNotIn('slug', ['owner', 'manager', 'employee'])->orderBy('id')->pluck('slug')->all(),
        );
    }

    public function test_slug_never_changes_on_edit(): void
    {
        $this->actingAs($this->owner());
        Livewire::test('pages::roles.form')->set('name', 'Caissier')->call('save');
        $role = Role::where('slug', 'caissier')->firstOrFail();

        Livewire::test('pages::roles.form', ['role' => $role])->set('name', 'Autre nom')->call('save')->assertHasNoErrors();

        $this->assertSame('caissier', $role->fresh()->slug);
        $this->assertSame('Autre nom', $role->fresh()->name);
    }

    public function test_creation_redirects_to_edit_and_saves_attributes(): void
    {
        $this->actingAs($this->owner());

        $component = Livewire::test('pages::roles.form')
            ->set('name', 'Caissier')
            ->set('description', 'Tient la caisse')
            ->set('color', '#ABCDEF')
            ->set('is_manager', true)
            ->call('save')
            ->assertHasNoErrors();

        $role = Role::where('slug', 'caissier')->firstOrFail();
        $component->assertRedirect(route('roles.edit', $role));
        $this->assertSame('Tient la caisse', $role->description);
        $this->assertSame('#ABCDEF', $role->color);
        $this->assertTrue($role->is_manager);
        $this->assertFalse($role->is_system);
    }

    public function test_validation(): void
    {
        $this->actingAs($this->owner());

        Livewire::test('pages::roles.form')->call('save')->assertHasErrors('name');
        Livewire::test('pages::roles.form')->set('name', str_repeat('a', 101))->call('save')->assertHasErrors('name');
        Livewire::test('pages::roles.form')->set('name', 'Ok')->set('color', 'red')->call('save')->assertHasErrors('color');
        Livewire::test('pages::roles.form')->set('name', 'Ok')->set('permissions', ['nope.nothing'])->call('save')->assertHasErrors('permissions.0');
    }

    public function test_permissions_and_all_stores_are_saved_and_applied(): void
    {
        $this->actingAs($this->owner());

        Livewire::test('pages::roles.form')
            ->set('name', 'Planner')
            ->set('permissions', ['shifts.view', 'shifts.update', 'employees.view'])
            ->set('allStores', ['shifts.view', 'payroll.view'])
            ->call('save')
            ->assertHasNoErrors();

        $role = Role::where('slug', 'planner')->firstOrFail();
        $this->assertEquals(
            ['employees.view' => false, 'shifts.update' => false, 'shifts.view' => true],
            $role->permissions->pluck('all_stores', 'permission')->sortKeys()->all(),
        );

        // Les droits donnés par le rôle sont évalués par l'Authorizer.
        $user = User::factory()->create();
        $user->assignRole($role, $this->store);
        $this->assertTrue(app(Authorizer::class)->hasUnrestricted($user, 'shifts.view'));
        $this->assertFalse(app(Authorizer::class)->hasUnrestricted($user, 'shifts.update'));
    }

    public function test_edit_form_shows_saved_permissions_and_removes_unchecked_ones(): void
    {
        $role = $this->customRole(['shifts.view', 'shifts.update'], ['shifts.view']);
        $this->actingAs($this->owner());

        Livewire::test('pages::roles.form', ['role' => $role])
            ->assertSet('permissions', ['shifts.update', 'shifts.view'])
            ->assertSet('allStores', ['shifts.view'])
            ->set('permissions', ['shifts.view'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['shifts.view'], $role->permissions()->pluck('permission')->all());
    }

    public function test_permission_outside_the_registry_is_kept_on_edit(): void
    {
        $role = $this->customRole(['shifts.view']);
        $role->permissions()->create(['permission' => 'gone_bundle.view', 'all_stores' => true]);
        $this->actingAs($this->owner());

        Livewire::test('pages::roles.form', ['role' => $role])
            ->assertSet('permissions', ['shifts.view'])
            ->set('permissions', ['employees.view'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals(
            ['employees.view' => false, 'gone_bundle.view' => true],
            $role->permissions()->pluck('all_stores', 'permission')->sortKeys()->all(),
        );
    }

    public function test_changed_permissions_apply_immediately_for_the_authorizer(): void
    {
        $role = $this->customRole(['shifts.view']);
        $user = User::factory()->create();
        $user->assignRole($role, $this->store);
        $authorizer = app(Authorizer::class);
        $this->assertTrue($authorizer->allows($user, 'shifts.view'));

        $this->actingAs($this->owner());
        Livewire::test('pages::roles.form', ['role' => $role])->set('permissions', [])->call('save');

        $this->assertFalse($authorizer->allows($user, 'shifts.view'));
    }

    public function test_system_role_cannot_be_edited_or_deleted(): void
    {
        $owner = $this->owner();
        $system = $this->role('owner');
        $this->actingAs($owner);

        Livewire::test('pages::roles.form', ['role' => $system])->call('save')->assertForbidden();
        Livewire::test('pages::roles.index')->call('delete', $system->id)->assertHasErrors('role');
        $this->assertNotNull($system->fresh());
    }

    public function test_role_with_holders_cannot_be_deleted_but_an_empty_one_can(): void
    {
        $role = $this->customRole([]);
        $holder = $this->employeeOf($this->store);
        $holder->assignRole($role, $this->store);
        $this->actingAs($this->owner());

        Livewire::test('pages::roles.index')->call('delete', $role->id)->assertHasErrors('role');
        $this->assertNotNull($role->fresh());

        $holder->removeRole($role, $this->store);
        Livewire::test('pages::roles.index')->call('delete', $role->id)->assertHasNoErrors();
        $this->assertNull($role->fresh());
    }

    public function test_holders_can_be_added_and_removed(): void
    {
        $role = $this->customRole([]);
        $user = User::factory()->create();
        $this->store->members()->attach($user);
        $this->actingAs($this->owner());

        $component = Livewire::test('pages::roles.form', ['role' => $role])
            ->set('holder_user', (string) $user->id)
            ->set('holder_store', (string) $this->store->id)
            ->call('addHolder')
            ->assertHasNoErrors();

        $assignment = $user->roles()->wherePivot('role_id', $role->id)->first()->pivot;
        $this->assertSame($this->store->id, $assignment->store_id);

        $component->set('holder_user', (string) $user->id)->set('holder_store', '')->call('addHolder')->assertHasNoErrors();
        $this->assertSame(2, $role->assignments()->count());
        $this->assertNotNull($role->assignments()->whereNull('store_id')->first());

        $component->call('removeHolder', $assignment->id)->assertHasNoErrors();
        $this->assertSame(1, $role->assignments()->count());
    }

    public function test_holder_must_be_active_and_member_of_the_store(): void
    {
        $role = $this->customRole([]);
        $inactive = User::factory()->inactive()->create();
        $stranger = User::factory()->create();
        $this->actingAs($this->owner());

        Livewire::test('pages::roles.form', ['role' => $role])
            ->set('holder_user', (string) $inactive->id)
            ->call('addHolder')
            ->assertHasErrors('holder_user')
            ->set('holder_user', (string) $stranger->id)
            ->set('holder_store', (string) $this->store->id)
            ->call('addHolder')
            ->assertHasErrors('holder_store');

        $this->assertSame(0, $role->assignments()->count());
    }

    public function test_user_cannot_get_a_second_role_in_the_same_store(): void
    {
        $role = $this->customRole([]);
        $user = $this->employeeOf($this->store);
        $this->actingAs($this->owner());

        Livewire::test('pages::roles.form', ['role' => $role])
            ->set('holder_user', (string) $user->id)
            ->set('holder_store', (string) $this->store->id)
            ->call('addHolder')
            ->assertHasErrors('holder_user');
    }

    public function test_owner_role_holders_page_only_allows_global_addition(): void
    {
        $system = $this->role('owner');
        $candidate = $this->employeeOf($this->store);
        $this->actingAs($this->owner());

        Livewire::test('pages::roles.form', ['role' => $system])
            ->assertSee('only its holders can be managed')
            ->set('holder_user', (string) $candidate->id)
            ->set('holder_store', (string) $this->store->id)
            ->call('addHolder')
            ->assertHasErrors('holder_store')
            ->set('holder_store', '')
            ->call('addHolder')
            ->assertHasNoErrors();

        $this->assertTrue(app(Authorizer::class)->isOwner($candidate->fresh()));
    }

    public function test_an_owner_cannot_remove_themselves_from_the_owner_role(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);
        $assignment = $owner->roles()->first()->pivot;

        Livewire::test('pages::roles.form', ['role' => $this->role('owner')])
            ->call('removeHolder', $assignment->id)
            ->assertHasErrors('holder');

        $this->assertTrue(app(Authorizer::class)->isOwner($owner->fresh()));
    }

    public function test_an_owner_can_remove_another_owner_while_one_remains(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $this->actingAs($owner);
        $assignment = $other->roles()->first()->pivot;

        Livewire::test('pages::roles.form', ['role' => $this->role('owner')])
            ->call('removeHolder', $assignment->id)
            ->assertHasNoErrors();

        $this->assertFalse(app(Authorizer::class)->isOwner($other->fresh()));
    }

    public function test_sidebar_links_follow_the_permissions(): void
    {
        $this->actingAs($this->managerOf($this->store));
        $this->get(route('dashboard'))->assertSee(route('employees.index'), false)->assertDontSee(route('roles.index'), false);

        $this->actingAs($this->owner());
        $this->get(route('dashboard'))->assertSee(route('employees.index'), false)->assertSee(route('roles.index'), false);

        $this->actingAs($this->employeeOf($this->store));
        $this->get(route('dashboard'))->assertDontSee(route('employees.index'), false)->assertDontSee(route('roles.index'), false);
    }
}
