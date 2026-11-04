<?php

namespace Tests\Feature\Employees;

use App\Actions\Employees\DeleteEmployee;
use App\Actions\Employees\ResetEmployeePassword;
use App\Events\EmployeePasswordReset;
use App\Models\ShiftType;
use App\Models\Store;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class EmployeeAccountActionsTest extends TestCase
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

    private function insertSession(User $user): void
    {
        DB::table('sessions')->insert([
            'id' => 'sess-'.$user->id.'-'.uniqid(),
            'user_id' => $user->id,
            'payload' => '',
            'last_activity' => time(),
        ]);
    }

    public function test_reset_sets_default_password_flag_and_revokes_sessions(): void
    {
        config(['session.driver' => 'database']);
        Event::fake([EmployeePasswordReset::class]);
        $employee = $this->employeeOf($this->storeA, ['email' => 'emp@example.com']);
        $other = $this->employeeOf($this->storeA);
        $this->insertSession($employee);
        $this->insertSession($other);
        $oldRememberToken = $employee->remember_token;
        $this->actingAs($this->managerOf($this->storeA));

        Livewire::test('pages::employees.edit', ['user' => $employee])->call('resetPassword')->assertHasNoErrors();

        $employee->refresh();
        $this->assertTrue(Hash::check('0000', $employee->password));
        $this->assertTrue($employee->must_change_password);
        $this->assertNotSame($oldRememberToken, $employee->remember_token);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $employee->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $other->id)->count());
        Event::assertDispatched(EmployeePasswordReset::class, fn (EmployeePasswordReset $e): bool => $e->userId === $employee->id);
    }

    public function test_opening_the_password_reset_confirmation_changes_nothing_and_confirming_resets(): void
    {
        $employee = $this->employeeOf($this->storeA, ['must_change_password' => false]);
        $this->actingAs($this->managerOf($this->storeA));

        $page = Livewire::test('pages::employees.edit', ['user' => $employee])->call('confirmResetPassword');
        $this->assertFalse($employee->fresh()->must_change_password);
        $this->assertStringNotContainsString('Reset the password to 0000 and sign the employee out everywhere?" data-test', $page->html());
        $this->assertStringNotContainsString('wire:confirm', $page->html());

        $page->call('resetPassword');
        $this->assertTrue($employee->fresh()->must_change_password);
    }

    public function test_reset_keeps_the_flag_when_it_was_already_set(): void
    {
        $employee = $this->employeeOf($this->storeA, ['must_change_password' => true]);
        $this->actingAs($this->managerOf($this->storeA));

        Livewire::test('pages::employees.edit', ['user' => $employee])->call('resetPassword');

        $this->assertTrue($employee->fresh()->must_change_password);
    }

    public function test_employee_can_sign_in_with_the_default_password(): void
    {
        $employee = $this->employeeOf($this->storeA, ['email' => 'emp@example.com']);
        $this->actingAs($this->managerOf($this->storeA));
        Livewire::test('pages::employees.edit', ['user' => $employee])->call('resetPassword');

        $this->app['auth']->guard('web')->logout();

        $this->post(route('login.store'), ['login' => 'emp@example.com', 'password' => '0000'])
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($employee);
        $this->assertTrue($employee->fresh()->must_change_password);
    }

    public function test_manager_can_set_a_password_with_or_without_change_request(): void
    {
        config(['session.driver' => 'database']);
        $employee = $this->employeeOf($this->storeA);
        $this->insertSession($employee);
        $this->actingAs($this->managerOf($this->storeA));

        Livewire::test('pages::employees.edit', ['user' => $employee])
            ->set('password', 'brand-new-pass')
            ->assertSet('must_change', true)
            ->call('setPassword')
            ->assertHasNoErrors();

        $employee->refresh();
        $this->assertTrue(Hash::check('brand-new-pass', $employee->password));
        $this->assertTrue($employee->must_change_password);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $employee->id)->count());

        Livewire::test('pages::employees.edit', ['user' => $employee])
            ->set('password', 'another-pass-1')
            ->set('must_change', false)
            ->call('setPassword')
            ->assertHasNoErrors();

        $employee->refresh();
        $this->assertTrue(Hash::check('another-pass-1', $employee->password));
        $this->assertFalse($employee->must_change_password);
    }

    public function test_too_short_password_is_refused(): void
    {
        $employee = $this->employeeOf($this->storeA);
        $this->actingAs($this->managerOf($this->storeA));

        Livewire::test('pages::employees.edit', ['user' => $employee])
            ->set('password', 'short')
            ->call('setPassword')
            ->assertHasErrors('password');

        $this->assertTrue(Hash::check('password', $employee->fresh()->password));
    }

    public function test_password_of_an_unmanaged_employee_cannot_be_reset(): void
    {
        $employee = $this->employeeOf($this->storeB);

        $this->expectException(AuthorizationException::class);
        app(ResetEmployeePassword::class)->handle($this->managerOf($this->storeA), $employee);
    }

    // --- Taux horaires personnels ---

    private function shiftType(Store $store, array $attributes = []): ShiftType
    {
        $type = ShiftType::factory()->create($attributes);
        $type->stores()->attach($store);

        return $type;
    }

    public function test_personal_rates_can_be_created_updated_and_removed(): void
    {
        $type = $this->shiftType($this->storeA);
        $employee = $this->employeeOf($this->storeA);
        $this->actingAs($this->managerOf($this->storeA));

        $component = Livewire::test('pages::employees.edit', ['user' => $employee])
            ->assertSet('rates.'.$type->id, '')
            ->set('rates.'.$type->id, '15.5')
            ->call('saveRates')
            ->assertHasNoErrors();

        $this->assertEquals(15.5, $employee->personalRates()->first()->pivot->hourly_rate);

        $component->assertSet('rates.'.$type->id, '15.50')
            ->set('rates.'.$type->id, '18')
            ->call('saveRates');
        $this->assertEquals(18, $employee->personalRates()->first()->pivot->hourly_rate);
        $this->assertSame(1, $employee->personalRates()->count());

        $component->set('rates.'.$type->id, '')->call('saveRates')->assertHasNoErrors();
        $this->assertSame(0, $employee->personalRates()->count());
    }

    public function test_rates_are_limited_to_types_of_stores_shared_with_the_actor(): void
    {
        $mine = $this->shiftType($this->storeA);
        $foreign = $this->shiftType($this->storeB);
        $inactive = $this->shiftType($this->storeA, ['is_active' => false]);
        $employee = $this->employeeOf($this->storeA);
        $employee->stores()->attach($this->storeB);
        $this->actingAs($this->managerOf($this->storeA));

        $component = Livewire::test('pages::employees.edit', ['user' => $employee]);
        $this->assertSame([$mine->id], array_map('intval', array_keys($component->get('rates'))));

        $component->set('rates.'.$foreign->id, '30')->call('saveRates')->assertHasErrors('rates.'.$foreign->id);
        $component->set('rates', [(string) $inactive->id => '30'])->call('saveRates')->assertHasErrors('rates.'.$inactive->id);

        $this->assertSame(0, $employee->personalRates()->count());
    }

    public function test_invalid_rate_values_are_refused(): void
    {
        $type = $this->shiftType($this->storeA);
        $employee = $this->employeeOf($this->storeA);
        $this->actingAs($this->managerOf($this->storeA));

        foreach (['-1', 'abc', '12.345'] as $value) {
            Livewire::test('pages::employees.edit', ['user' => $employee])
                ->set('rates.'.$type->id, $value)
                ->call('saveRates')
                ->assertHasErrors('rates.'.$type->id);
        }

        $this->assertSame(0, $employee->personalRates()->count());
    }

    public function test_rates_of_other_types_are_left_untouched(): void
    {
        $a = $this->shiftType($this->storeA);
        $b = $this->shiftType($this->storeA);
        $employee = $this->employeeOf($this->storeA);
        $employee->personalRates()->attach($b->id, ['hourly_rate' => 20]);
        $this->actingAs($this->managerOf($this->storeA));

        Livewire::test('pages::employees.edit', ['user' => $employee])
            ->set('rates', [(string) $a->id => '11'])
            ->call('saveRates');

        $this->assertSame(2, $employee->personalRates()->count());
    }

    // --- Suppression ---

    public function test_manager_deletes_an_employee_which_anonymizes_the_account(): void
    {
        $employee = $this->employeeOf($this->storeA, ['email' => 'gone@example.com']);
        $this->actingAs($this->managerOf($this->storeA));

        Livewire::test('pages::employees.edit', ['user' => $employee])
            ->call('delete')
            ->assertRedirect(route('employees.index'));

        $this->assertSoftDeleted($employee);
        $trashed = User::withTrashed()->find($employee->id);
        $this->assertStringStartsWith('deleted-', $trashed->email);
        $this->assertFalse($trashed->is_active);
    }

    public function test_non_owner_cannot_delete_themselves(): void
    {
        $manager = $this->managerOf($this->storeA);
        $this->storeA->members()->attach($manager);
        $this->actingAs($manager);

        Livewire::test('pages::employees.edit', ['user' => $manager])
            ->call('delete')
            ->assertHasErrors('delete');

        $this->assertNull($manager->fresh()->deleted_at);
    }

    public function test_manager_cannot_delete_an_owner(): void
    {
        $owner = $this->owner();
        $this->storeA->members()->attach($owner);

        $this->expectException(AuthorizationException::class);
        app(DeleteEmployee::class)->handle($this->managerOf($this->storeA), $owner);
    }

    public function test_owner_cannot_delete_themselves_but_can_delete_another_owner(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $this->actingAs($owner);

        Livewire::test('pages::employees.edit', ['user' => $owner])->call('delete')->assertHasErrors('delete');
        $this->assertNull($owner->fresh()->deleted_at);

        Livewire::test('pages::employees.edit', ['user' => $other])->call('delete')->assertHasNoErrors();
        $this->assertSoftDeleted($other);
    }

    public function test_user_without_delete_permission_cannot_delete(): void
    {
        $employee = $this->employeeOf($this->storeA);
        $viewer = User::factory()->create();
        $viewer->assignRole($this->customRole(['employees.view', 'employees.update']), $this->storeA);
        $this->actingAs($viewer);

        Livewire::test('pages::employees.edit', ['user' => $employee])->call('delete')->assertForbidden();
        $this->assertNull($employee->fresh()->deleted_at);
    }
}
