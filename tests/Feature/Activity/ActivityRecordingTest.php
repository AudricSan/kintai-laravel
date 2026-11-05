<?php

namespace Tests\Feature\Activity;

use App\Actions\ChangePassword;
use App\Actions\Roles\DeleteRole;
use App\Actions\Roles\ManageRoleHolders;
use App\Actions\Roles\SaveRole;
use App\Actions\Shifts\CreateShift;
use App\Actions\ShiftTypes\DeleteShiftType;
use App\Actions\UpdateUserProfile;
use App\Activity\ActivityLogger;
use App\Models\ActivityEntry;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\Store;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Kintai\Contracts\Events\EmployeeCreated;
use Kintai\Contracts\Events\EmployeePasswordReset;
use Kintai\Contracts\Events\EmployeeRatesUpdated;
use Kintai\Contracts\Events\EmployeeUpdated;
use Kintai\Contracts\Events\ShiftCreated;
use Kintai\Contracts\Events\ShiftDeleted;
use Kintai\Contracts\Events\ShiftReplaced;
use Kintai\Contracts\Events\ShiftRestored;
use Kintai\Contracts\Events\ShiftTypeCreated;
use Kintai\Contracts\Events\ShiftTypeUpdated;
use Kintai\Contracts\Events\ShiftUpdated;
use Kintai\Contracts\Events\StoreCreated;
use Kintai\Contracts\Events\StoreDeleted;
use Kintai\Contracts\Events\StoreMemberAdded;
use Kintai\Contracts\Events\StoreMemberRemoved;
use Kintai\Contracts\Events\StoreMemberUpdated;
use Kintai\Contracts\Events\StoreUpdated;
use Kintai\Contracts\Events\UserAnonymized;
use RuntimeException;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class ActivityRecordingTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;

    private Store $store;

    private User $manager;

    private User $emma;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->store = Store::factory()->create(['name' => 'Alpha', 'code' => 'ALP']);
        $this->manager = $this->managerOf($this->store);
        $this->emma = $this->employeeOf($this->store, ['name' => 'Emma']);
    }

    private function only(string $action): ActivityEntry
    {
        $entries = ActivityEntry::query()->where('action', $action)->get();
        $this->assertCount(1, $entries, "Expected exactly one [{$action}] entry.");

        return $entries->first();
    }

    private function shift(?User $user = null, string $date = '2026-11-02', string $start = '09:00', string $end = '17:00'): Shift
    {
        return Shift::factory()->create([
            'store_id' => $this->store->id, 'user_id' => $user?->id, 'shift_date' => $date,
            'starts_at' => Shift::localTime($date, $start), 'ends_at' => Shift::localTime($date, $end),
        ])->fresh();
    }

    public function test_employee_events_are_recorded_with_the_connected_actor(): void
    {
        $this->actingAs($this->manager);

        EmployeeCreated::dispatch($this->emma->id, 'import');
        EmployeeUpdated::dispatch($this->emma->id);
        EmployeePasswordReset::dispatch($this->emma->id);
        EmployeeRatesUpdated::dispatch($this->emma->id);

        $created = $this->only('employee.created');
        $this->assertSame($this->manager->id, $created->actor_id);
        $this->assertSame((new User)->getMorphClass(), $created->subject_type);
        $this->assertSame($this->emma->id, $created->subject_id);
        $this->assertNull($created->store_id);
        $this->assertSame(['name' => 'Emma', 'source' => 'import'], $created->properties);

        foreach (['employee.updated', 'employee.password_reset', 'employee.rates_updated'] as $action) {
            $this->assertSame($this->emma->id, $this->only($action)->subject_id);
        }
    }

    public function test_anonymization_is_recorded_with_the_user_id_only(): void
    {
        $this->actingAs($this->manager);

        UserAnonymized::dispatch($this->emma->id);

        $entry = $this->only('employee.anonymized');
        $this->assertSame($this->emma->id, $entry->subject_id);
        $this->assertNull($entry->properties);
    }

    public function test_store_events_carry_the_store_and_its_name(): void
    {
        $this->actingAs($this->manager);

        StoreCreated::dispatch($this->store->id);
        StoreUpdated::dispatch($this->store->id);

        $created = $this->only('store.created');
        $this->assertSame($this->store->id, $created->store_id);
        $this->assertSame($this->store->id, $created->subject_id);
        $this->assertSame(['name' => 'Alpha', 'code' => 'ALP'], $created->properties);
        $this->assertSame($this->store->id, $this->only('store.updated')->store_id);

        // Le magasin est supprimé en douceur : l'entrée reste lisible.
        $this->store->delete();
        StoreDeleted::dispatch($this->store->id);

        $deleted = $this->only('store.deleted');
        $this->assertSame($this->store->id, $deleted->store_id);
        $this->assertSame('Alpha', $deleted->properties['name']);
    }

    public function test_store_member_events_target_the_member_in_the_store(): void
    {
        $this->actingAs($this->manager);

        StoreMemberAdded::dispatch($this->store->id, $this->emma->id);
        StoreMemberUpdated::dispatch($this->store->id, $this->emma->id);
        StoreMemberRemoved::dispatch($this->store->id, $this->emma->id);

        foreach (['store.member_added', 'store.member_updated', 'store.member_removed'] as $action) {
            $entry = $this->only($action);
            $this->assertSame($this->store->id, $entry->store_id);
            $this->assertSame($this->emma->id, $entry->subject_id);
            $this->assertSame(['name' => 'Emma', 'store' => 'Alpha'], $entry->properties);
        }
    }

    public function test_shift_type_events_keep_the_name_after_deletion(): void
    {
        $this->actingAs($this->manager);
        $type = ShiftType::factory()->create(['name' => 'Morning', 'code' => 'MOR']);
        $type->stores()->attach($this->store);

        ShiftTypeCreated::dispatch($type->id);
        ShiftTypeUpdated::dispatch($type->id);

        $this->assertSame(['name' => 'Morning', 'code' => 'MOR'], $this->only('shift_type.created')->properties);
        $this->assertSame($type->id, $this->only('shift_type.updated')->subject_id);

        app(DeleteShiftType::class)->handle($this->managerWithShiftRights(), $type);

        $deleted = $this->only('shift_type.deleted');
        $this->assertSame($type->id, $deleted->subject_id);
        $this->assertSame(['name' => 'Morning', 'code' => 'MOR'], $deleted->properties);
    }

    private function managerWithShiftRights(): User
    {
        $user = User::factory()->create();
        $user->assignRole($this->customRole(['shifts.view', 'shifts.create', 'shifts.update', 'shifts.delete']), $this->store);

        return $user;
    }

    public function test_shift_created_through_the_action_is_recorded_with_slot_and_employee(): void
    {
        $actor = $this->managerWithShiftRights();

        $shift = app(CreateShift::class)->handle($actor, [
            'store_id' => $this->store->id, 'user_id' => $this->emma->id, 'shift_date' => '2026-11-02',
            'start_time' => '09:00', 'end_time' => '17:00', 'pause_minutes' => 60,
        ]);

        $entry = $this->only('shift.created');
        $this->assertSame($actor->id, $entry->actor_id);
        $this->assertSame($this->store->id, $entry->store_id);
        $this->assertSame($shift->id, $entry->subject_id);
        $this->assertSame('Emma', $entry->properties['employee']);
        $this->assertSame('Alpha', $entry->properties['store']);
        $this->assertSame('2026-11-02', $entry->properties['date']);
        $this->assertSame('09:00', $entry->properties['start']);
        $this->assertSame('17:00', $entry->properties['end']);
    }

    public function test_deleted_and_restored_shifts_stay_readable(): void
    {
        $shift = $this->shift($this->emma);
        $shift->delete();

        ShiftDeleted::dispatch($shift->id, $this->manager->id);
        ShiftRestored::dispatch($shift->id, $this->manager->id);

        $deleted = $this->only('shift.deleted');
        $this->assertSame($this->manager->id, $deleted->actor_id);
        $this->assertSame($this->store->id, $deleted->store_id);
        $this->assertSame('Emma', $deleted->properties['employee']);
        $this->assertSame($shift->id, $this->only('shift.restored')->subject_id);
    }

    public function test_open_shift_is_flagged_without_employee(): void
    {
        $shift = $this->shift();

        ShiftCreated::dispatch($shift->id, $this->manager->id);

        $properties = $this->only('shift.created')->properties;
        $this->assertTrue($properties['open_shift']);
        $this->assertArrayNotHasKey('employee', $properties);
    }

    public function test_shift_update_records_previous_employee_and_slot(): void
    {
        $colin = $this->employeeOf($this->store, ['name' => 'Colin']);
        $shift = $this->shift($colin, '2026-11-03', '10:00', '18:00');

        ShiftUpdated::dispatch(
            $shift->id,
            previousUserId: $this->emma->id,
            userChanged: true,
            rescheduled: true,
            actorId: $this->manager->id,
            previousSlot: ['store_id' => $this->store->id, 'date' => '2026-11-02', 'start' => '09:00', 'end' => '17:00'],
        );

        $entry = $this->only('shift.updated');
        $this->assertSame($this->manager->id, $entry->actor_id);
        $this->assertSame('Colin', $entry->properties['employee']);
        $this->assertSame('Emma', $entry->properties['previous_employee']);
        $this->assertSame('2026-11-02', $entry->properties['previous_date']);
        $this->assertSame('09:00', $entry->properties['previous_start']);
        $this->assertSame('17:00', $entry->properties['previous_end']);
        $this->assertSame('Alpha', $entry->properties['previous_store']);
    }

    public function test_shift_replacement_targets_the_new_shift(): void
    {
        $old = $this->shift($this->emma);
        $new = $this->shift($this->employeeOf($this->store, ['name' => 'Colin']), '2026-11-03');
        $old->delete();

        ShiftReplaced::dispatch($old->id, $new->id, $this->manager->id);

        $entry = $this->only('shift.replaced');
        $this->assertSame($new->id, $entry->subject_id);
        $this->assertSame($this->store->id, $entry->store_id);
        $this->assertSame($old->id, $entry->properties['replaced_shift_id']);
        $this->assertSame('Emma', $entry->properties['previous_employee']);
        $this->assertSame('Colin', $entry->properties['employee']);
    }

    public function test_role_actions_are_recorded(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);

        $role = app(SaveRole::class)->handle($owner, null, ['name' => 'Supervisor', 'color' => '#112233', 'permissions' => ['shifts.view']]);
        $this->assertSame(['name' => 'Supervisor'], $this->only('role.created')->properties);

        app(SaveRole::class)->handle($owner, $role, ['name' => 'Supervisor 2', 'color' => '#112233', 'permissions' => ['shifts.view']]);
        $this->assertSame($role->id, $this->only('role.updated')->subject_id);

        $member = User::factory()->create(['name' => 'Emma']);
        $this->store->members()->attach($member);

        app(ManageRoleHolders::class)->add($owner, $role, $member->id, $this->store->id);
        $added = $this->only('role.holders_changed');
        $this->assertSame($this->store->id, $added->store_id);
        $this->assertSame(['name' => 'Supervisor 2', 'change' => 'added', 'employee' => 'Emma', 'store' => 'Alpha'], $added->properties);

        $assignment = $role->assignments()->firstOrFail();
        app(ManageRoleHolders::class)->remove($owner, $role, $assignment->id);
        $this->assertSame(2, ActivityEntry::where('action', 'role.holders_changed')->count());
        $this->assertSame('removed', ActivityEntry::where('action', 'role.holders_changed')->latest('id')->firstOrFail()->properties['change']);

        app(DeleteRole::class)->handle($owner, $role);
        $deleted = $this->only('role.deleted');
        $this->assertSame($role->id, $deleted->subject_id);
        $this->assertSame(['name' => 'Supervisor 2'], $deleted->properties);
        $this->assertSame($owner->id, $deleted->actor_id);
    }

    public function test_password_and_profile_changes_are_recorded_without_the_password(): void
    {
        $this->actingAs($this->emma);

        app(ChangePassword::class)->handle($this->emma, 'a-brand-new-secret');
        app(UpdateUserProfile::class)->handle($this->emma, ['locale' => 'fr']);

        $changed = $this->only('user.password_changed');
        $this->assertSame($this->emma->id, $changed->actor_id);
        $this->assertSame($this->emma->id, $changed->subject_id);
        $this->assertSame($this->emma->id, $this->only('user.profile_updated')->subject_id);
        $this->assertStringNotContainsString('a-brand-new-secret', (string) json_encode(ActivityEntry::all()->toArray()));
    }

    public function test_password_reset_by_the_user_is_recorded(): void
    {
        event(new PasswordReset($this->emma));

        $entry = $this->only('user.password_reset');
        $this->assertSame($this->emma->id, $entry->actor_id);
        $this->assertSame($this->emma->id, $entry->subject_id);
    }

    public function test_login_and_logout_are_recorded(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.com']);

        $this->post(route('login.store'), ['login' => 'jane@example.com', 'password' => 'password']);
        $login = $this->only('auth.login');
        $this->assertSame($user->id, $login->actor_id);
        $this->assertSame($user->id, $login->subject_id);
        $this->assertNull($login->store_id);

        $this->post(route('logout'));
        $this->assertSame($user->id, $this->only('auth.logout')->actor_id);
    }

    public function test_failed_login_keeps_the_identifier_but_never_the_password(): void
    {
        User::factory()->create(['email' => 'jane@example.com']);

        $this->post(route('login.store'), ['login' => 'jane@example.com', 'password' => 'hunter2-wrong']);

        $entry = $this->only('auth.login_failed');
        $this->assertNull($entry->actor_id);
        $this->assertSame(['login' => 'jane@example.com'], $entry->properties);
        $this->assertStringNotContainsString('hunter2-wrong', (string) json_encode($entry->toArray()));
    }

    public function test_sensitive_keys_are_filtered_recursively(): void
    {
        $entry = app(ActivityLogger::class)->record('custom.action', null, null, [
            'name' => 'kept',
            'password' => 'secret-1',
            'Password_Confirmation' => 'secret-2',
            'nested' => ['api_token' => 'secret-3', 'remember_token' => 'secret-4', 'ok' => 'fine', 'deeper' => ['client_secret' => 'secret-5', 'label' => 'x']],
            'two_factor_recovery_codes' => ['a', 'b'],
        ]);

        $this->assertSame(['name' => 'kept', 'nested' => ['ok' => 'fine', 'deeper' => ['label' => 'x']]], $entry->properties);
        $this->assertStringNotContainsString('secret-', (string) json_encode($entry->fresh()->toArray()));
    }

    public function test_a_failing_journal_never_breaks_the_action(): void
    {
        Exceptions::fake();
        ActivityEntry::creating(fn () => throw new RuntimeException('journal is down'));

        $shift = app(CreateShift::class)->handle($this->managerWithShiftRights(), [
            'store_id' => $this->store->id, 'user_id' => $this->emma->id, 'shift_date' => '2026-11-02',
            'start_time' => '09:00', 'end_time' => '17:00', 'pause_minutes' => 60,
        ]);

        $this->assertTrue($shift->exists);
        $this->assertDatabaseHas('shifts', ['id' => $shift->id]);
        $this->assertSame(0, ActivityEntry::count());
        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'journal is down');
    }

    public function test_the_ip_cannot_be_spoofed_with_forwarded_headers(): void
    {
        User::factory()->create(['email' => 'jane@example.com']);

        $this->withHeaders(['X-Forwarded-For' => '203.0.113.9', 'X-Real-IP' => '203.0.113.10', 'Client-IP' => '203.0.113.11'])
            ->post(route('login.store'), ['login' => 'jane@example.com', 'password' => 'wrong-password']);

        $this->assertSame('127.0.0.1', $this->only('auth.login_failed')->ip_address);
    }

    public function test_the_user_agent_is_truncated_to_255_characters(): void
    {
        User::factory()->create(['email' => 'jane@example.com']);

        $this->withHeader('User-Agent', str_repeat('a', 400))
            ->post(route('login.store'), ['login' => 'jane@example.com', 'password' => 'wrong-password']);

        $this->assertSame(str_repeat('a', 255), $this->only('auth.login_failed')->user_agent);
    }

    public function test_an_explicit_actor_overrides_the_connected_user(): void
    {
        $this->actingAs($this->manager);

        $entry = app(ActivityLogger::class)->record('custom.action', $this->store, $this->store->id, [], $this->emma);

        $this->assertSame($this->emma->id, $entry->actor_id);
    }
}
