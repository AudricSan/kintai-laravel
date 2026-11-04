<?php

namespace Tests\Feature\Models;

use App\Models\Availability;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchemaConstraintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_a_store_cascades_to_its_shifts_and_memberships(): void
    {
        $store = Store::factory()->create();
        $user = User::factory()->create();
        $user->stores()->attach($store->id, ['staff_code' => 'A1']);
        Shift::factory()->for($store)->for($user)->create();
        Availability::factory()->for($store)->for($user)->create();

        $store->forceDelete();

        $this->assertSame(0, Shift::withTrashed()->count());
        $this->assertSame(0, Availability::count());
        $this->assertDatabaseCount('store_user', 0);
        $this->assertNotNull($user->fresh());
    }

    public function test_deleting_a_shift_type_nulls_shift_type_id(): void
    {
        $type = ShiftType::factory()->create();
        $shift = Shift::factory()->create(['shift_type_id' => $type->id]);

        $type->delete();

        $this->assertNull($shift->fresh()?->shift_type_id);
    }

    public function test_deleting_a_user_nulls_shift_user_id_and_creator(): void
    {
        $user = User::factory()->create();
        $shift = Shift::factory()->create(['user_id' => $user->id, 'created_by' => $user->id]);

        $user->forceDelete();

        $shift = $shift->fresh();
        $this->assertNotNull($shift);
        $this->assertNull($shift->user_id);
        $this->assertNull($shift->created_by);
    }

    public function test_deleting_a_user_cascades_to_profile_and_role_assignments(): void
    {
        $user = User::factory()->hasProfile()->create();
        $role = Role::factory()->create();
        $user->roles()->attach($role->id, ['store_id' => null]);

        $user->forceDelete();

        $this->assertDatabaseCount('employee_profiles', 0);
        $this->assertDatabaseCount('role_user', 0);
    }

    public function test_email_must_be_unique(): void
    {
        User::factory()->create(['email' => 'a@example.com']);

        $this->expectException(QueryException::class);
        User::factory()->create(['email' => 'a@example.com']);
    }

    public function test_employee_code_must_be_unique(): void
    {
        User::factory()->create(['employee_code' => 'E1']);

        $this->expectException(QueryException::class);
        User::factory()->create(['employee_code' => 'E1']);
    }

    public function test_store_code_must_be_unique(): void
    {
        Store::factory()->create(['code' => 'TKY']);

        $this->expectException(QueryException::class);
        Store::factory()->create(['code' => 'TKY']);
    }

    public function test_store_membership_must_be_unique(): void
    {
        $store = Store::factory()->create();
        $user = User::factory()->create();
        $user->stores()->attach($store->id);

        $this->expectException(QueryException::class);
        $user->stores()->attach($store->id);
    }

    public function test_staff_code_must_be_unique_per_store(): void
    {
        $store = Store::factory()->create();
        User::factory()->create()->stores()->attach($store->id, ['staff_code' => 'S1']);

        $this->expectException(QueryException::class);
        User::factory()->create()->stores()->attach($store->id, ['staff_code' => 'S1']);
    }

    public function test_same_staff_code_is_allowed_in_different_stores(): void
    {
        User::factory()->create()->stores()->attach(Store::factory()->create()->id, ['staff_code' => 'S1']);
        User::factory()->create()->stores()->attach(Store::factory()->create()->id, ['staff_code' => 'S1']);

        $this->assertDatabaseCount('store_user', 2);
    }

    public function test_personal_rate_must_be_unique_per_user_and_type(): void
    {
        $user = User::factory()->create();
        $type = ShiftType::factory()->create();
        $user->personalRates()->attach($type->id, ['hourly_rate' => 15]);

        $this->expectException(QueryException::class);
        $user->personalRates()->attach($type->id, ['hourly_rate' => 16]);
    }

    public function test_ical_uid_must_be_unique(): void
    {
        $first = Shift::factory()->create();

        $this->expectException(QueryException::class);
        Shift::factory()->create(['ical_uid' => $first->ical_uid]);
    }
}
