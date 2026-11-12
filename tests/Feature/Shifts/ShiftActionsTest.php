<?php

namespace Tests\Feature\Shifts;

use App\Actions\Shifts\CreateShift;
use App\Actions\Shifts\DeleteShift;
use App\Actions\Shifts\DeleteShifts;
use App\Actions\Shifts\MoveShift;
use App\Actions\Shifts\RestoreShift;
use App\Actions\Shifts\UpdateShift;
use App\Exceptions\ShiftWarningsException;
use App\Models\Availability;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\Store;
use App\Models\User;
use App\Planning\NullTimeOffChecker;
use App\Planning\ShiftIssueKind;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Kintai\Contracts\Events\ShiftCreated;
use Kintai\Contracts\Events\ShiftDeleted;
use Kintai\Contracts\Events\ShiftRestored;
use Kintai\Contracts\Events\ShiftUpdated;
use Kintai\Contracts\Planning\TimeOffChecker;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class ShiftActionsTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;

    private Store $store;

    private Store $other;

    private User $manager;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->store = Store::factory()->create();
        $this->other = Store::factory()->create();
        $this->manager = $this->managerWith(['shifts.view', 'shifts.create', 'shifts.update', 'shifts.delete'], $this->store);
        $this->employee = $this->employeeOf($this->store);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function managerWith(array $permissions, Store $store): User
    {
        $user = User::factory()->create();
        $user->assignRole($this->customRole($permissions), $store);

        return $user;
    }

    /**
     * 2026-11-02 est un lundi.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
    {
        return $overrides + [
            'store_id' => $this->store->id,
            'user_id' => $this->employee->id,
            'shift_date' => '2026-11-02',
            'start_time' => '09:00',
            'end_time' => '17:00',
            'pause_minutes' => 60,
        ];
    }

    private function create(array $overrides = [], bool $force = false, ?User $actor = null): Shift
    {
        return app(CreateShift::class)->handle($actor ?? $this->manager, $this->input($overrides), $force);
    }

    private function fakeTimeOff(bool $result): void
    {
        $this->app->bind(TimeOffChecker::class, fn () => new class($result) implements TimeOffChecker
        {
            public function __construct(private readonly bool $result) {}

            public function hasApprovedTimeOff(int $userId, CarbonInterface $date): bool
            {
                return $this->result;
            }
        });
    }

    /**
     * @return list<ShiftIssueKind>
     */
    private function warningKinds(callable $callback): array
    {
        try {
            $callback();
        } catch (ShiftWarningsException $e) {
            return array_map(fn ($issue) => $issue->kind, $e->issues);
        }

        return [];
    }

    public function test_default_time_off_checker_never_blocks(): void
    {
        $this->assertInstanceOf(NullTimeOffChecker::class, app(TimeOffChecker::class));
    }

    public function test_creates_a_shift_in_utc_with_creator_event_and_ical_uid(): void
    {
        Event::fake([ShiftCreated::class]);
        config(['kintai.timezone' => 'Asia/Tokyo']);

        $shift = $this->create();

        $this->assertSame('2026-11-02 00:00:00', $shift->starts_at->toDateTimeString());
        $this->assertSame('2026-11-02 08:00:00', $shift->ends_at->toDateTimeString());
        $this->assertSame($this->manager->id, $shift->created_by);
        $this->assertNotEmpty($shift->ical_uid);
        $this->assertSame(420, $shift->netMinutes);
        Event::assertDispatched(ShiftCreated::class, fn (ShiftCreated $e): bool => $e->shiftId === $shift->id);
    }

    public function test_end_before_start_crosses_midnight(): void
    {
        $shift = $this->create(['start_time' => '20:00', 'end_time' => '02:00', 'pause_minutes' => 0], true);

        $this->assertTrue($shift->crossesMidnight);
        $this->assertSame(360, $shift->durationMinutes);
    }

    public function test_start_equal_to_end_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->create(['start_time' => '09:00', 'end_time' => '09:00']);
    }

    public function test_pause_and_notes_are_bounded(): void
    {
        foreach ([['pause_minutes' => 1441], ['pause_minutes' => -1], ['notes' => str_repeat('a', 2001)]] as $bad) {
            try {
                $this->create($bad);
                $this->fail('Validation expected');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
    }

    public function test_dominant_shift_type_is_computed_among_active_types_of_the_store(): void
    {
        $morning = ShiftType::factory()->create(['starts_at' => '06:00', 'ends_at' => '12:00']);
        $day = ShiftType::factory()->create(['starts_at' => '12:00', 'ends_at' => '20:00']);
        $inactive = ShiftType::factory()->create(['starts_at' => '08:00', 'ends_at' => '20:00', 'is_active' => false]);
        $elsewhere = ShiftType::factory()->create(['starts_at' => '08:00', 'ends_at' => '20:00']);
        foreach ([$morning, $day, $inactive] as $type) {
            $type->stores()->attach($this->store);
        }
        $elsewhere->stores()->attach($this->other);

        $shift = $this->create(['start_time' => '10:00', 'end_time' => '17:00']);

        $this->assertSame($day->id, $shift->shift_type_id);
    }

    public function test_no_dominant_type_without_matching_type(): void
    {
        $this->assertNull($this->create()->shift_type_id);
    }

    public function test_open_shift_skips_employee_checks(): void
    {
        $this->fakeTimeOff(true);

        $shift = $this->create(['user_id' => null]);

        $this->assertNull($shift->user_id);
    }

    public function test_empty_string_employee_means_open_shift(): void
    {
        $this->assertNull($this->create(['user_id' => ''])->user_id);
    }

    public function test_open_shift_still_checks_duration(): void
    {
        $kinds = $this->warningKinds(fn () => $this->create(['user_id' => null, 'start_time' => '09:00', 'end_time' => '10:00']));

        $this->assertSame([ShiftIssueKind::TooShort], $kinds);
    }

    public function test_non_member_is_refused(): void
    {
        $stranger = User::factory()->create();

        try {
            $this->create(['user_id' => $stranger->id]);
            $this->fail('Validation expected');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('user_id', $e->errors());
        }
    }

    public function test_inactive_member_is_refused(): void
    {
        $this->store->members()->updateExistingPivot($this->employee->id, ['is_active' => false]);

        $this->expectException(ValidationException::class);

        $this->create();
    }

    public function test_approved_time_off_blocks_even_with_force(): void
    {
        $this->fakeTimeOff(true);

        try {
            $this->create(force: true);
            $this->fail('Validation expected');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('user_id', $e->errors());
        }
        $this->assertSame(0, Shift::count());
    }

    public function test_unavailability_warns_then_passes_with_force(): void
    {
        Availability::factory()->unavailable()->create(['user_id' => $this->employee->id, 'store_id' => $this->store->id, 'day_of_week' => 1]);

        $this->assertSame([ShiftIssueKind::Unavailable], $this->warningKinds(fn () => $this->create()));
        $this->assertSame(0, Shift::count());

        $this->create(force: true);
        $this->assertSame(1, Shift::count());
    }

    public function test_unavailability_of_another_store_or_day_is_ignored(): void
    {
        Availability::factory()->unavailable()->create(['user_id' => $this->employee->id, 'store_id' => $this->other->id, 'day_of_week' => 1]);
        Availability::factory()->unavailable()->create(['user_id' => $this->employee->id, 'store_id' => $this->store->id, 'day_of_week' => 2]);

        $this->assertInstanceOf(Shift::class, $this->create());
    }

    public function test_overlap_with_another_shift_warns_all_warnings_together(): void
    {
        $this->create();
        Availability::factory()->unavailable()->create(['user_id' => $this->employee->id, 'store_id' => $this->store->id, 'day_of_week' => 1]);

        $kinds = $this->warningKinds(fn () => $this->create(['start_time' => '16:00', 'end_time' => '17:00']));

        $this->assertSame([ShiftIssueKind::Unavailable, ShiftIssueKind::Overlap, ShiftIssueKind::TooShort], $kinds);
    }

    public function test_adjacent_shifts_do_not_overlap(): void
    {
        $this->create(['start_time' => '09:00', 'end_time' => '13:00']);

        $this->assertInstanceOf(Shift::class, $this->create(['start_time' => '13:00', 'end_time' => '17:00']));
    }

    public function test_overlap_across_midnight(): void
    {
        $this->create(['start_time' => '22:00', 'end_time' => '06:00', 'pause_minutes' => 0]);

        $kinds = $this->warningKinds(fn () => $this->create(['shift_date' => '2026-11-03', 'start_time' => '04:00', 'end_time' => '10:00']));

        $this->assertSame([ShiftIssueKind::Overlap], $kinds);
    }

    public function test_overlap_is_detected_across_stores(): void
    {
        $this->other->members()->attach($this->employee);
        $this->create();

        $kinds = $this->warningKinds(fn () => $this->create(['store_id' => $this->other->id], actor: $this->owner()));

        $this->assertSame([ShiftIssueKind::Overlap], $kinds);
    }

    public function test_deleted_and_replaced_shifts_are_ignored_by_overlap(): void
    {
        $deleted = $this->create();
        $deleted->delete();
        $replaced = Shift::factory()->create([
            'store_id' => $this->store->id, 'user_id' => $this->employee->id, 'shift_date' => '2026-11-02',
            'starts_at' => Shift::localTime('2026-11-02', '09:00'), 'ends_at' => Shift::localTime('2026-11-02', '17:00'),
        ]);
        $replacer = Shift::factory()->create(['store_id' => $this->store->id, 'user_id' => $this->employeeOf($this->store)->id, 'shift_date' => '2026-11-09']);
        $replaced->forceFill(['replaced_by_id' => $replacer->id])->saveQuietly();

        $this->assertInstanceOf(Shift::class, $this->create());
    }

    public function test_open_shift_is_not_an_overlap_for_anyone(): void
    {
        Shift::factory()->open()->create(['store_id' => $this->store->id, 'shift_date' => '2026-11-02']);

        $this->assertInstanceOf(Shift::class, $this->create());
    }

    public function test_duration_out_of_bounds_warns(): void
    {
        $this->assertSame([ShiftIssueKind::TooShort], $this->warningKinds(fn () => $this->create(['start_time' => '09:00', 'end_time' => '10:00'])));
        $this->assertSame([ShiftIssueKind::TooLong], $this->warningKinds(fn () => $this->create(['start_time' => '06:00', 'end_time' => '16:00'])));

        $this->assertInstanceOf(Shift::class, $this->create(['start_time' => '06:00', 'end_time' => '16:00'], true));
    }

    public function test_rights_are_checked_per_store(): void
    {
        $this->other->members()->attach($this->employee);

        $this->expectException(AuthorizationException::class);

        $this->create(['store_id' => $this->other->id]);
    }

    public function test_create_needs_the_create_permission(): void
    {
        $viewer = $this->managerWith(['shifts.view', 'shifts.update'], $this->store);

        $this->expectException(AuthorizationException::class);

        $this->create(actor: $viewer);
    }

    public function test_overrides_are_reserved_to_payroll_generate_or_owner(): void
    {
        try {
            $this->create(['hourly_rate_override' => 20]);
            $this->fail('Authorization expected');
        } catch (AuthorizationException) {
            $this->assertSame(0, Shift::count());
        }

        $payroll = $this->managerWith(['shifts.create', 'payroll.generate'], $this->store);
        $this->assertSame('20.00', $this->create(['hourly_rate_override' => 20, 'net_minutes_override' => 300], actor: $payroll)->hourly_rate_override);

        $this->assertSame(300, $this->create(['user_id' => null, 'hourly_rate_override' => 20, 'net_minutes_override' => 300], actor: $this->owner())->net_minutes_override);
    }

    public function test_update_keeps_unchanged_overrides_without_payroll_right_but_refuses_changes(): void
    {
        $shift = Shift::factory()->withOverrides(15.0, 420)->create(['store_id' => $this->store->id, 'user_id' => $this->employee->id, 'shift_date' => '2026-11-02']);

        $updated = app(UpdateShift::class)->handle($this->manager, $shift, ['notes' => 'ok', 'hourly_rate_override' => '15']);
        $this->assertSame('ok', $updated->notes);

        $this->expectException(AuthorizationException::class);
        app(UpdateShift::class)->handle($this->manager, $shift, ['hourly_rate_override' => '99']);
    }

    public function test_update_increments_ical_sequence_only_when_something_changes(): void
    {
        $shift = $this->create();
        $this->assertSame(0, $shift->ical_sequence);

        app(UpdateShift::class)->handle($this->manager, $shift, ['notes' => 'A']);
        $this->assertSame(1, $shift->fresh()->ical_sequence);

        app(UpdateShift::class)->handle($this->manager, $shift->fresh(), ['notes' => 'A']);
        $this->assertSame(1, $shift->fresh()->ical_sequence);
    }

    public function test_update_dispatches_the_event_and_recomputes_the_dominant_type(): void
    {
        $type = ShiftType::factory()->create(['starts_at' => '12:00', 'ends_at' => '20:00']);
        $type->stores()->attach($this->store);
        $shift = $this->create();
        Event::fake([ShiftUpdated::class]);

        $updated = app(UpdateShift::class)->handle($this->manager, $shift, ['start_time' => '13:00', 'end_time' => '19:00']);

        $this->assertSame($type->id, $updated->shift_type_id);
        Event::assertDispatched(ShiftUpdated::class);
    }

    public function test_update_does_not_ask_again_for_unrelated_changes(): void
    {
        $shift = $this->create();
        // Indisponibilité déclarée après coup : modifier les notes ne redemande rien.
        Availability::factory()->unavailable()->create(['user_id' => $this->employee->id, 'store_id' => $this->store->id, 'day_of_week' => 1]);

        $updated = app(UpdateShift::class)->handle($this->manager, $shift, ['notes' => 'Salut']);

        $this->assertSame('Salut', $updated->notes);
    }

    public function test_update_to_another_store_needs_rights_on_both(): void
    {
        $this->other->members()->attach($this->employee);
        $shift = $this->create();

        $this->expectException(AuthorizationException::class);

        app(UpdateShift::class)->handle($this->manager, $shift, ['store_id' => $this->other->id]);
    }

    public function test_manager_of_another_store_cannot_update(): void
    {
        $shift = $this->create();

        $this->expectException(AuthorizationException::class);

        app(UpdateShift::class)->handle($this->managerWith(['shifts.update'], $this->other), $shift, ['notes' => 'x']);
    }

    public function test_cannot_update_a_deleted_shift(): void
    {
        $shift = $this->create();
        $shift->delete();

        $this->expectException(ValidationException::class);

        app(UpdateShift::class)->handle($this->manager, $shift, ['notes' => 'x']);
    }

    public function test_move_changes_day_and_employee_only(): void
    {
        $colleague = $this->employeeOf($this->store);
        $shift = $this->create(['notes' => 'garde']);

        $moved = app(MoveShift::class)->handle($this->manager, $shift, [
            'shift_date' => '2026-11-04', 'user_id' => $colleague->id, 'notes' => 'ignoré', 'pause_minutes' => 0,
        ]);

        $this->assertSame('2026-11-04', $moved->shift_date->toDateString());
        $this->assertSame($colleague->id, $moved->user_id);
        $this->assertSame('garde', $moved->notes);
        $this->assertSame(60, $moved->pause_minutes);
    }

    public function test_move_warns_about_unavailability_of_the_new_day(): void
    {
        $shift = $this->create();
        Availability::factory()->unavailable()->create(['user_id' => $this->employee->id, 'store_id' => $this->store->id, 'day_of_week' => 3]);

        $kinds = $this->warningKinds(fn () => app(MoveShift::class)->handle($this->manager, $shift, ['shift_date' => '2026-11-04']));

        $this->assertSame([ShiftIssueKind::Unavailable], $kinds);
    }

    public function test_move_to_an_open_shift(): void
    {
        $moved = app(MoveShift::class)->handle($this->manager, $this->create(), ['user_id' => null]);

        $this->assertNull($moved->user_id);
    }

    public function test_delete_needs_the_delete_permission_for_the_store(): void
    {
        $shift = $this->create();

        try {
            app(DeleteShift::class)->handle($this->managerWith(['shifts.update'], $this->store), $shift);
            $this->fail('Authorization expected');
        } catch (AuthorizationException) {
            $this->assertNull($shift->fresh()?->deleted_at);
        }

        Event::fake([ShiftDeleted::class]);
        app(DeleteShift::class)->handle($this->manager, $shift);

        $this->assertSoftDeleted($shift);
        Event::assertDispatched(ShiftDeleted::class);
    }

    public function test_bulk_delete_checks_each_shift_and_is_all_or_nothing(): void
    {
        $mine = $this->create();
        $foreign = Shift::factory()->create(['store_id' => $this->other->id]);

        try {
            app(DeleteShifts::class)->handle($this->manager, [$mine->id, $foreign->id]);
            $this->fail('Authorization expected');
        } catch (AuthorizationException) {
            $this->assertNotSoftDeleted($mine);
        }

        $second = $this->create(['user_id' => null]);
        $this->assertSame(2, app(DeleteShifts::class)->handle($this->manager, [$mine->id, $second->id, 999999]));
        $this->assertSoftDeleted($mine);
        $this->assertSoftDeleted($second);
    }

    public function test_restore_a_deleted_shift(): void
    {
        $shift = $this->create();
        app(DeleteShift::class)->handle($this->manager, $shift);
        Event::fake([ShiftRestored::class]);

        $restored = app(RestoreShift::class)->handle($this->manager, $shift->fresh() ?? Shift::withTrashed()->findOrFail($shift->id));

        $this->assertFalse($restored->trashed());
        Event::assertDispatched(ShiftRestored::class);
    }

    public function test_restore_needs_a_deleted_shift_and_the_right(): void
    {
        $shift = $this->create();

        try {
            app(RestoreShift::class)->handle($this->manager, $shift);
            $this->fail('Validation expected');
        } catch (ValidationException) {
        }

        $shift->delete();
        $this->expectException(AuthorizationException::class);
        app(RestoreShift::class)->handle($this->managerWith(['shifts.update'], $this->store), $shift);
    }
}
