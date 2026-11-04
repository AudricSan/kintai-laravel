<?php

namespace Tests\Feature\Employees;

use App\Actions\Employees\CreateEmployee;
use App\Authorization\Authorizer;
use App\Events\EmployeeCreated;
use App\Models\Store;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class CreateEmployeeTest extends TestCase
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

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
    {
        return $overrides + [
            'last_name' => 'Martin',
            'first_name' => 'Alice',
            'furigana_last_name' => 'マルタン',
            'furigana_first_name' => 'アリス',
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function fill(Testable $component, array $input): Testable
    {
        foreach ($input as $key => $value) {
            $component->set($key, $value);
        }

        return $component;
    }

    public function test_full_creation_by_an_owner(): void
    {
        Event::fake([EmployeeCreated::class]);
        $this->actingAs($this->owner());

        $component = $this->fill(Livewire::test('pages::employees.create'), $this->input([
            'name' => 'Alice M.',
            'email' => 'Alice@Example.com',
            'employee_code' => 'a-042',
            'password' => 'secret-pass',
            'color' => '#112233',
            'store_id' => (string) $this->storeA->id,
            'role_id' => (string) $this->role('manager')->id,
        ]))->call('save')->assertHasNoErrors();

        $user = User::where('employee_code', 'A-042')->firstOrFail();
        $component->assertRedirect(route('employees.edit', $user));

        $this->assertSame('Alice M.', $user->name);
        $this->assertSame('alice@example.com', $user->email);
        $this->assertSame('#112233', $user->color);
        $this->assertTrue(Hash::check('secret-pass', $user->password));
        $this->assertFalse($user->must_change_password);
        $this->assertTrue($user->is_active);
        $this->assertTrue($this->storeA->members()->whereKey($user->id)->exists());
        $this->assertSame(['manager'], $user->roles->pluck('slug')->all());
        $this->assertSame($this->storeA->id, $user->roles->first()->pivot->store_id);
        Event::assertDispatched(EmployeeCreated::class, fn (EmployeeCreated $e): bool => $e->userId === $user->id && $e->source === 'form');
    }

    public function test_defaults_empty_password_and_names(): void
    {
        $this->actingAs($this->owner());

        $this->fill(Livewire::test('pages::employees.create'), $this->input())->call('save')->assertHasNoErrors();

        $user = User::where('first_name', 'Alice')->firstOrFail();
        $this->assertTrue(Hash::check('0000', $user->password));
        $this->assertTrue($user->must_change_password);
        $this->assertSame('Martin Alice', $user->name);
        $this->assertNull($user->email);
        $this->assertNull($user->employee_code);
        $this->assertSame('#3B82F6', $user->color);
        $this->assertSame(0, $user->stores()->count());
        $this->assertSame(0, $user->roles()->count());
    }

    public function test_default_role_is_employee_and_membership_is_created(): void
    {
        $this->actingAs($this->managerOf($this->storeA));

        $this->fill(Livewire::test('pages::employees.create'), $this->input(['store_id' => (string) $this->storeA->id, 'role_id' => '']))
            ->call('save')->assertHasNoErrors();

        $user = User::where('first_name', 'Alice')->firstOrFail();
        $this->assertSame(['employee'], $user->roles->pluck('slug')->all());
        $this->assertTrue($this->storeA->members()->whereKey($user->id)->exists());
    }

    public function test_furigana_and_names_are_required(): void
    {
        $this->actingAs($this->owner());

        Livewire::test('pages::employees.create')
            ->call('save')
            ->assertHasErrors(['first_name', 'last_name', 'furigana_first_name', 'furigana_last_name']);

        $this->assertSame(1, User::count());
    }

    public function test_duplicate_email_and_code_are_refused_including_live(): void
    {
        $this->actingAs($this->owner());
        User::factory()->create(['email' => 'taken@example.com', 'employee_code' => 'TAKEN']);

        Livewire::test('pages::employees.create')
            ->set('email', 'Taken@Example.com')
            ->assertHasErrors('email')
            ->set('email', 'free@example.com')
            ->assertHasNoErrors('email')
            ->set('employee_code', 'taken')
            ->assertSet('employee_code', 'TAKEN')
            ->assertHasErrors('employee_code');

        $this->fill(Livewire::test('pages::employees.create'), $this->input(['email' => 'taken@example.com', 'employee_code' => 'taken']))
            ->call('save')
            ->assertHasErrors(['email', 'employee_code']);
    }

    public function test_invalid_code_is_refused(): void
    {
        $this->actingAs($this->owner());

        $this->fill(Livewire::test('pages::employees.create'), $this->input(['employee_code' => 'no spaces!']))
            ->call('save')
            ->assertHasErrors('employee_code');
    }

    public function test_restricted_manager_must_pick_one_of_their_stores(): void
    {
        Store::factory()->create();
        $this->actingAs($this->managerOf($this->storeA));

        // Deux magasins existent mais le gérant n'a que le sien : il est présélectionné, donc on le vide à la main.
        $this->fill(Livewire::test('pages::employees.create'), $this->input(['store_id' => '']))
            ->call('save')->assertHasErrors('store_id');

        $this->fill(Livewire::test('pages::employees.create'), $this->input(['store_id' => (string) $this->storeB->id]))
            ->call('save')->assertHasErrors('store_id');

        $this->assertSame(0, User::where('first_name', 'Alice')->count());
    }

    public function test_role_more_powerful_than_the_actor_is_refused(): void
    {
        $powerful = $this->customRole(['employees.view', 'stores.create']);
        $this->actingAs($this->managerOf($this->storeA));

        $this->fill(Livewire::test('pages::employees.create'), $this->input([
            'store_id' => (string) $this->storeA->id,
            'role_id' => (string) $powerful->id,
        ]))->call('save')->assertHasErrors('role_id');

        $this->assertSame(0, User::where('first_name', 'Alice')->count());
    }

    public function test_system_role_cannot_be_picked_as_store_role(): void
    {
        $this->actingAs($this->owner());

        $this->fill(Livewire::test('pages::employees.create'), $this->input([
            'store_id' => (string) $this->storeA->id,
            'role_id' => (string) $this->role('owner')->id,
        ]))->call('save')->assertHasErrors('role_id');
    }

    public function test_owner_checkbox_is_refused_for_a_non_owner(): void
    {
        $this->actingAs($this->managerOf($this->storeA));

        Livewire::test('pages::employees.create')->assertDontSee('Full access to the whole application');

        $this->fill(Livewire::test('pages::employees.create'), $this->input([
            'store_id' => (string) $this->storeA->id,
            'is_owner' => true,
        ]))->call('save')->assertForbidden();

        $this->assertSame(0, User::where('first_name', 'Alice')->count());
    }

    public function test_owner_can_create_an_owner_without_store_role(): void
    {
        $this->actingAs($this->owner());

        Livewire::test('pages::employees.create')->assertSee('Full access to the whole application');

        $this->fill(Livewire::test('pages::employees.create'), $this->input([
            'store_id' => (string) $this->storeA->id,
            'is_owner' => true,
        ]))->call('save')->assertHasNoErrors();

        $user = User::where('first_name', 'Alice')->firstOrFail();
        $this->assertTrue(app(Authorizer::class)->isOwner($user));
        $this->assertSame(1, $user->roles()->count());
        $this->assertTrue($this->storeA->members()->whereKey($user->id)->exists());
    }

    public function test_action_is_reusable_with_another_source(): void
    {
        Event::fake([EmployeeCreated::class]);

        $user = app(CreateEmployee::class)->handle($this->owner(), $this->input(), 'import');

        Event::assertDispatched(EmployeeCreated::class, fn (EmployeeCreated $e): bool => $e->userId === $user->id && $e->source === 'import');
    }

    public function test_user_without_permission_cannot_create(): void
    {
        $employee = $this->employeeOf($this->storeA);

        $this->expectException(AuthorizationException::class);
        app(CreateEmployee::class)->handle($employee, $this->input());
    }
}
