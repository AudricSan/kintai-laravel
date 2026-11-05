<?php

namespace Tests\Feature\Shifts;

use App\Actions\Shifts\ExchangeShifts;
use App\Exceptions\ShiftWarningsException;
use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Kintai\Contracts\Events\ShiftReplaced;
use Kintai\Contracts\Planning\TimeOffChecker;
use Kintai\Contracts\Shifts\ShiftExchanger;
use Kintai\Contracts\Shifts\ShiftWarnings;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

/**
 * Contrat `ShiftExchanger` : échange atomique de deux shifts, garde-fous des deux affectations, rien d'écrit en cas de refus.
 */
class ExchangeShiftsTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;

    private Store $store;

    private User $manager;

    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->store = Store::factory()->create();
        $this->manager = User::factory()->create();
        $this->manager->assignRole($this->customRole(['shifts.view', 'shifts.create', 'shifts.update', 'shifts.delete']), $this->store);
        $this->alice = $this->employeeOf($this->store);
        $this->bob = $this->employeeOf($this->store);
    }

    private function shift(User $user, string $date, string $start = '09:00', string $end = '17:00', array $attributes = []): Shift
    {
        return Shift::factory()->create($attributes + [
            'store_id' => $this->store->id, 'user_id' => $user->id, 'shift_date' => $date,
            'starts_at' => Shift::localTime($date, $start), 'ends_at' => Shift::localTime($date, $end),
        ]);
    }

    public function test_the_contract_is_bound_to_the_core_operation(): void
    {
        $this->assertInstanceOf(ExchangeShifts::class, app(ShiftExchanger::class));
    }

    public function test_two_shifts_change_hands_and_the_old_ones_are_replaced(): void
    {
        Event::fake([ShiftReplaced::class]);
        $a = $this->shift($this->alice, '2026-11-10', '09:00', '17:00', ['notes' => 'Opening', 'hourly_rate_override' => '15.00']);
        $b = $this->shift($this->bob, '2026-11-12', '13:00', '21:00');

        [$newA, $newB] = app(ShiftExchanger::class)->exchange($this->manager, $a->id, $b->id);

        $this->assertSoftDeleted($a);
        $this->assertSoftDeleted($b);
        $this->assertSame($newA, Shift::withTrashed()->find($a->id)->replaced_by_id);
        $this->assertSame($newB, Shift::withTrashed()->find($b->id)->replaced_by_id);

        $first = Shift::findOrFail($newA);
        $this->assertSame($this->bob->id, $first->user_id);
        $this->assertSame('2026-11-10', $first->shift_date->toDateString());
        $this->assertSame('09:00', $first->localStartsAt->format('H:i'));
        $this->assertSame('Opening', $first->notes);
        $this->assertNull($first->hourly_rate_override);

        $second = Shift::findOrFail($newB);
        $this->assertSame($this->alice->id, $second->user_id);
        $this->assertSame('13:00', $second->localStartsAt->format('H:i'));

        Event::assertDispatchedTimes(ShiftReplaced::class, 2);
    }

    public function test_overlapping_shifts_of_the_two_employees_can_be_exchanged(): void
    {
        $a = $this->shift($this->alice, '2026-11-10', '09:00', '17:00');
        $b = $this->shift($this->bob, '2026-11-10', '12:00', '20:00');

        // Chacun reçoit un shift qui chevauche celui qu'il perd : sans effet, car il disparaît dans l'échange.
        [$newA, $newB] = app(ShiftExchanger::class)->exchange($this->manager, $a->id, $b->id);

        $this->assertSame($this->bob->id, Shift::findOrFail($newA)->user_id);
        $this->assertSame($this->alice->id, Shift::findOrFail($newB)->user_id);
    }

    public function test_warnings_of_both_assignments_are_returned_together_and_nothing_is_written(): void
    {
        $a = $this->shift($this->alice, '2026-11-10', '09:00', '17:00');
        $b = $this->shift($this->bob, '2026-11-11', '09:00', '17:00');
        // Alice a déjà un shift qui chevauche celui de Bob ; Bob, un qui chevauche celui d'Alice.
        $this->shift($this->alice, '2026-11-11', '10:00', '12:00');
        $this->shift($this->bob, '2026-11-10', '10:00', '12:00');

        try {
            app(ShiftExchanger::class)->exchange($this->manager, $a->id, $b->id);
            $this->fail('The overlaps should need a confirmation.');
        } catch (ShiftWarnings $e) {
            $this->assertInstanceOf(ShiftWarningsException::class, $e);
            $this->assertCount(2, $e->messages());
        }

        $this->assertNull(Shift::withTrashed()->find($a->id)->deleted_at);
        $this->assertNull(Shift::withTrashed()->find($b->id)->deleted_at);
        $this->assertSame(4, Shift::query()->count());

        [$newA, $newB] = app(ShiftExchanger::class)->exchange($this->manager, $a->id, $b->id, true);

        $this->assertSame($this->bob->id, Shift::findOrFail($newA)->user_id);
        $this->assertSame($this->alice->id, Shift::findOrFail($newB)->user_id);
    }

    public function test_the_actor_needs_the_shift_rights_on_both_stores(): void
    {
        $a = $this->shift($this->alice, '2026-11-10');
        $b = $this->shift($this->bob, '2026-11-11');

        $this->expectException(AuthorizationException::class);

        try {
            app(ShiftExchanger::class)->exchange($this->alice, $a->id, $b->id);
        } finally {
            $this->assertNull(Shift::withTrashed()->find($a->id)->deleted_at);
        }
    }

    public function test_invalid_pairs_are_refused_without_writing(): void
    {
        $a = $this->shift($this->alice, '2026-11-10');
        $same = $this->shift($this->alice, '2026-11-11');
        $open = $this->shift($this->alice, '2026-11-12', attributes: ['user_id' => null]);
        $deleted = $this->shift($this->bob, '2026-11-13');
        $deleted->delete();
        $replaced = $this->shift($this->bob, '2026-11-14');
        $replaced->forceFill(['replaced_by_id' => $a->id])->saveQuietly();

        foreach ([[$a->id, $a->id], [$a->id, $same->id], [$a->id, $open->id], [$a->id, $deleted->id], [$a->id, $replaced->id], [$a->id, 999999]] as [$one, $two]) {
            try {
                app(ShiftExchanger::class)->exchange($this->manager, $one, $two);
                $this->fail("Exchange of [{$one}, {$two}] should be refused.");
            } catch (ValidationException) {
                $this->assertNull(Shift::withTrashed()->find($a->id)->deleted_at);
            }
        }
    }

    public function test_an_approved_time_off_blocks_even_when_forced(): void
    {
        $this->app->bind(TimeOffChecker::class, fn () => new class implements TimeOffChecker
        {
            public function hasApprovedTimeOff(int $userId, CarbonInterface $date): bool
            {
                return $date->toDateString() === '2026-11-11';
            }
        });
        $a = $this->shift($this->alice, '2026-11-10');
        $b = $this->shift($this->bob, '2026-11-11');

        $this->expectException(ValidationException::class);

        try {
            app(ShiftExchanger::class)->exchange($this->manager, $a->id, $b->id, true);
        } finally {
            $this->assertNull(Shift::withTrashed()->find($a->id)->deleted_at);
            $this->assertSame(2, Shift::query()->count());
        }
    }
}
