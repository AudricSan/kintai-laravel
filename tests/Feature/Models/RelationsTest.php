<?php

namespace Tests\Feature\Models;

use App\Models\Availability;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\Store;
use App\Models\StoreFeature;
use App\Models\StoreMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_profile_relation(): void
    {
        $user = User::factory()->create();
        EmployeeProfile::factory()->for($user)->create(['gender' => 'female']);

        $this->assertSame('female', $user->profile?->gender);
        $this->assertTrue($user->profile->user->is($user));
    }

    public function test_user_roles_carry_the_store_on_the_pivot(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $manager = Role::factory()->manager()->create();
        $employee = Role::factory()->create();
        $user->roles()->attach($manager->id, ['store_id' => $store->id]);
        $user->roles()->attach($employee->id, ['store_id' => null]);

        $roles = $user->roles()->get()->keyBy('id');

        $this->assertCount(2, $roles);
        $this->assertInstanceOf(RoleAssignment::class, $roles[$manager->id]->pivot);
        $this->assertSame($store->id, $roles[$manager->id]->pivot->store_id);
        $this->assertNull($roles[$employee->id]->pivot->store_id);
        $this->assertNotNull($roles[$manager->id]->pivot->id);
        $this->assertCount(2, $manager->assignments->merge($employee->assignments));
    }

    public function test_role_permissions_relation(): void
    {
        $role = Role::factory()->create();
        $role->permissions()->create(['permission' => 'shifts.view', 'all_stores' => true]);

        $permission = $role->permissions()->first();

        $this->assertNotNull($permission);
        $this->assertSame('shifts.view', $permission->permission);
        $this->assertTrue($permission->all_stores);
        $this->assertTrue($permission->role->is($role));
    }

    public function test_user_store_membership_pivot(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create();
        $user->stores()->attach($store->id, ['staff_code' => 'K01', 'hire_date' => '2026-04-01', 'contract_type' => 'cdi']);

        $membership = $user->stores()->first()?->pivot;

        $this->assertInstanceOf(StoreMembership::class, $membership);
        $this->assertSame('K01', $membership->staff_code);
        $this->assertSame('2026-04-01', $membership->hire_date?->toDateString());
        $this->assertTrue($membership->is_active);
        $this->assertTrue($store->members->first()?->is($user));
    }

    public function test_store_features_shift_types_and_shifts(): void
    {
        $store = Store::factory()->create();
        StoreFeature::factory()->for($store)->disabled()->create(['feature' => 'swap']);
        $type = ShiftType::factory()->create();
        $store->shiftTypes()->attach($type);
        $shift = Shift::factory()->for($store)->create();

        $this->assertFalse($store->features->first()?->enabled);
        $this->assertTrue($store->shiftTypes->first()?->is($type));
        $this->assertTrue($type->stores->first()?->is($store));
        $this->assertTrue($store->shifts->first()?->is($shift));
        $this->assertTrue($shift->store->is($store));
    }

    public function test_personal_rates(): void
    {
        $user = User::factory()->create();
        $type = ShiftType::factory()->create(['hourly_rate' => 12]);
        $user->personalRates()->attach($type->id, ['hourly_rate' => 15.5]);

        $this->assertEquals(15.5, $user->personalRates->first()?->pivot->hourly_rate);
        $this->assertTrue($type->rates->first()?->is($user));
    }

    public function test_shift_relations(): void
    {
        $creator = User::factory()->create();
        $user = User::factory()->create();
        $type = ShiftType::factory()->create();
        $shift = Shift::factory()->for($user)->create(['created_by' => $creator->id, 'shift_type_id' => $type->id]);

        $this->assertTrue($shift->user?->is($user));
        $this->assertTrue($shift->creator?->is($creator));
        $this->assertTrue($shift->shiftType?->is($type));
        $this->assertTrue($user->shifts->first()?->is($shift));
    }

    public function test_availabilities_relation(): void
    {
        $user = User::factory()->create();
        $availability = Availability::factory()->for($user)->create();

        $this->assertTrue($user->availabilities->first()?->is($availability));
        $this->assertTrue($availability->user->is($user));
        $this->assertNotNull($availability->store);
    }

    public function test_time_columns_are_exposed_as_hours_and_minutes(): void
    {
        $user = User::factory()->create(['quiet_hours_start' => '22:00:00', 'quiet_hours_end' => '07:30']);
        $type = ShiftType::factory()->create(['starts_at' => '09:00:00']);

        $this->assertSame('22:00', $user->fresh()?->quiet_hours_start);
        $this->assertSame('07:30', $user->fresh()?->quiet_hours_end);
        $this->assertSame('09:00', $type->fresh()?->starts_at);
    }

    public function test_user_states(): void
    {
        $this->assertFalse(User::factory()->inactive()->create()->is_active);

        $anonymized = User::factory()->anonymized()->create();
        $this->assertTrue($anonymized->trashed());
        $this->assertNull(User::find($anonymized->id));
        $this->assertNotNull(User::withTrashed()->find($anonymized->id));
    }
}
