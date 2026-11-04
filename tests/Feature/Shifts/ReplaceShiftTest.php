<?php

namespace Tests\Feature\Shifts;

use App\Actions\Shifts\ReplaceShift;
use App\Actions\Shifts\ResolveConflict;
use App\Actions\Shifts\RestoreShift;
use App\Events\ShiftReplaced;
use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use App\Planning\ShiftConflictFinder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class ReplaceShiftTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;

    private Store $store;

    private User $manager;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->store = Store::factory()->create();
        $this->manager = User::factory()->create();
        $this->manager->assignRole($this->customRole(['shifts.view', 'shifts.create', 'shifts.update', 'shifts.delete']), $this->store);
        $this->employee = $this->employeeOf($this->store);
    }

    private function shift(array $attributes = []): Shift
    {
        $date = $attributes['shift_date'] ?? '2026-11-02';

        return Shift::factory()->create($attributes + [
            'store_id' => $this->store->id, 'user_id' => $this->employee->id, 'shift_date' => $date,
            'starts_at' => Shift::localTime($date, '09:00'), 'ends_at' => Shift::localTime($date, '17:00'),
        ]);
    }

    private function input(): array
    {
        return [
            'store_id' => $this->store->id, 'user_id' => $this->employee->id, 'shift_date' => '2026-11-02',
            'start_time' => '10:00', 'end_time' => '16:00', 'pause_minutes' => 30,
        ];
    }

    public function test_new_replacement_takes_over_the_ical_uid_with_sequence_plus_one(): void
    {
        Event::fake([ShiftReplaced::class]);
        $old = $this->shift();
        $uid = $old->ical_uid;
        $old->forceFill(['ical_sequence' => 4])->saveQuietly();

        // Le remplaçant chevauche l'ancien : celui-ci ne compte pas dans les chevauchements.
        $new = app(ReplaceShift::class)->handle($this->manager, $old->fresh(), $this->input());

        $this->assertSame($uid, $new->ical_uid);
        $this->assertSame(5, $new->ical_sequence);
        $old = Shift::withTrashed()->findOrFail($old->id);
        $this->assertSoftDeleted($old);
        $this->assertSame($new->id, $old->replaced_by_id);
        $this->assertNotSame($uid, $old->ical_uid);
        Event::assertDispatched(ShiftReplaced::class, fn (ShiftReplaced $e): bool => $e->replacedShiftId === $old->id && $e->replacementShiftId === $new->id);
    }

    public function test_existing_replacement_keeps_every_ical_uid(): void
    {
        $old = $this->shift();
        $existing = $this->shift(['user_id' => null]);
        $oldUid = $old->ical_uid;
        $existingUid = $existing->ical_uid;
        $existingSequence = $existing->ical_sequence;

        app(ReplaceShift::class)->handle($this->manager, $old, $existing);

        $this->assertSame($existingUid, $existing->fresh()->ical_uid);
        $this->assertSame($existingSequence, $existing->fresh()->ical_sequence);
        $trashed = Shift::withTrashed()->findOrFail($old->id);
        $this->assertSame($oldUid, $trashed->ical_uid);
        $this->assertSame($existing->id, $trashed->replaced_by_id);
        $this->assertSoftDeleted($trashed);
    }

    public function test_replaced_shift_cannot_be_restored_while_the_replacement_lives(): void
    {
        $old = $this->shift();
        $new = $this->shift(['user_id' => null]);
        app(ReplaceShift::class)->handle($this->manager, $old, $new);

        try {
            app(RestoreShift::class)->handle($this->manager, Shift::withTrashed()->findOrFail($old->id));
            $this->fail('Validation expected');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('shift', $e->errors());
        }
        $this->assertSoftDeleted($old);

        // Le remplaçant part à la corbeille : l'ancien redevient restaurable.
        $new->delete();
        $restored = app(RestoreShift::class)->handle($this->manager, Shift::withTrashed()->findOrFail($old->id));
        $this->assertFalse($restored->trashed());
    }

    public function test_replacement_must_be_another_active_shift(): void
    {
        $old = $this->shift();

        $this->expectException(ValidationException::class);

        app(ReplaceShift::class)->handle($this->manager, $old, $old);
    }

    public function test_replace_needs_rights_on_both_stores(): void
    {
        $other = Store::factory()->create();
        $old = $this->shift();
        $foreign = Shift::factory()->create(['store_id' => $other->id]);

        $this->expectException(AuthorizationException::class);

        app(ReplaceShift::class)->handle($this->manager, $old, $foreign);
    }

    public function test_resolve_conflict_keeps_the_newer_shift(): void
    {
        $older = $this->shift();
        $newer = $this->shift(['shift_date' => '2026-11-02']);

        $kept = app(ResolveConflict::class)->handle($this->manager, $newer, $older);

        $this->assertTrue($kept?->is($newer));
        $this->assertSoftDeleted($older);
        $this->assertSame($newer->id, Shift::withTrashed()->findOrFail($older->id)->replaced_by_id);
        $this->assertNotSoftDeleted($newer);
    }

    public function test_resolve_conflict_refuses_shifts_that_do_not_overlap(): void
    {
        $a = $this->shift();
        $b = $this->shift(['shift_date' => '2026-11-03']);

        $this->expectException(ValidationException::class);

        app(ResolveConflict::class)->handle($this->manager, $a, $b);
    }

    public function test_resolve_conflict_does_nothing_when_one_has_already_gone(): void
    {
        $a = $this->shift();
        $b = $this->shift();
        $a->delete();

        $this->assertNull(app(ResolveConflict::class)->handle($this->manager, $a, $b));
        $this->assertNotSoftDeleted($b);
    }

    public function test_resolve_conflict_needs_the_update_permission(): void
    {
        $a = $this->shift();
        $b = $this->shift();
        $viewer = User::factory()->create();
        $viewer->assignRole($this->customRole(['shifts.view']), $this->store);

        $this->expectException(AuthorizationException::class);

        app(ResolveConflict::class)->handle($viewer, $a, $b);
    }

    public function test_replaced_shift_is_left_out_of_the_conflicts(): void
    {
        $older = $this->shift();
        $newer = $this->shift();
        $finder = app(ShiftConflictFinder::class);
        $this->assertCount(1, $finder->overlappingPairs(Shift::all()));

        app(ResolveConflict::class)->handle($this->manager, $older, $newer);

        $this->assertCount(0, $finder->overlappingPairs(Shift::all()));
    }
}
