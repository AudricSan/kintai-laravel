<?php

namespace Tests\Feature\Models;

use App\Exceptions\ShiftRestoreException;
use App\Models\Availability;
use App\Models\Shift;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShiftTest extends TestCase
{
    use RefreshDatabase;

    public function test_duration_of_a_regular_shift(): void
    {
        $shift = Shift::factory()->create();

        $this->assertSame(480, $shift->durationMinutes);
        $this->assertFalse($shift->crossesMidnight);
    }

    public function test_duration_of_a_shift_crossing_midnight(): void
    {
        $shift = Shift::factory()->crossingMidnight()->create();

        $this->assertSame(480, $shift->durationMinutes);
        $this->assertTrue($shift->crossesMidnight);
    }

    public function test_duration_on_the_spring_forward_night(): void
    {
        config(['kintai.timezone' => 'Europe/Paris']);

        $shift = Shift::fromLocalTimes('2026-03-29', '01:00', '04:00', ['pause_minutes' => 0]);
        $shift->store_id = Store::factory()->create()->id;
        $shift->save();
        $shift = $shift->fresh();

        $this->assertNotNull($shift);
        $this->assertSame(120, $shift->durationMinutes);
        $this->assertSame(120, $shift->netMinutes);
        $this->assertFalse($shift->crossesMidnight);
    }

    public function test_duration_on_the_fall_back_night(): void
    {
        config(['kintai.timezone' => 'Europe/Paris']);

        $shift = Shift::fromLocalTimes('2026-10-25', '01:00', '04:00', ['store_id' => Store::factory()->create()->id]);
        $shift->save();

        $this->assertSame(240, $shift->fresh()?->durationMinutes);
    }

    public function test_times_are_stored_in_utc_and_exposed_in_local_time(): void
    {
        config(['kintai.timezone' => 'Europe/Paris']);

        $shift = Shift::fromLocalTimes('2026-03-29', '01:00', '04:00', ['store_id' => Store::factory()->create()->id]);
        $shift->save();

        $raw = DB::table('shifts')->where('id', $shift->id)->first();
        $this->assertNotNull($raw);
        $this->assertSame('2026-03-29 00:00:00', $raw->starts_at);
        $this->assertSame('2026-03-29 02:00:00', $raw->ends_at);
        $this->assertSame('2026-03-29', substr((string) $raw->shift_date, 0, 10));

        $fresh = $shift->fresh();
        $this->assertSame('01:00', $fresh?->localStartsAt->format('H:i'));
        $this->assertSame('04:00', $fresh?->localEndsAt->format('H:i'));
        $this->assertSame('Europe/Paris', $fresh?->localStartsAt->getTimezone()->getName());
    }

    public function test_local_shift_crossing_midnight(): void
    {
        config(['kintai.timezone' => 'Europe/Paris']);

        $night = Shift::fromLocalTimes('2026-01-10', '22:00', '06:00');
        $this->assertTrue($night->crossesMidnight);
        $this->assertSame(480, $night->durationMinutes);
        $this->assertSame('2026-01-11', $night->localEndsAt->toDateString());

        // 00:30 -> 02:00 à Paris en hiver : 23:30 -> 01:00 UTC, mais même jour local.
        $early = Shift::fromLocalTimes('2026-01-10', '00:30', '02:00');
        $this->assertFalse($early->crossesMidnight);
        $this->assertSame(90, $early->durationMinutes);
    }

    public function test_factory_builds_times_from_local_hours(): void
    {
        config(['kintai.timezone' => 'Europe/Paris']);

        $shift = Shift::factory()->crossingMidnight()->make(['shift_date' => '2026-01-10']);

        $this->assertSame('22:00', $shift->localStartsAt->format('H:i'));
        $this->assertSame('06:00', $shift->localEndsAt->format('H:i'));
        $this->assertTrue($shift->crossesMidnight);
    }

    public function test_net_minutes_subtracts_the_pause(): void
    {
        $shift = Shift::factory()->create(['pause_minutes' => 45]);

        $this->assertSame(435, $shift->netMinutes);
    }

    public function test_net_minutes_override_wins(): void
    {
        $shift = Shift::factory()->withOverrides(15, 300)->create();

        $this->assertSame(300, $shift->netMinutes);
        $this->assertEquals(15, $shift->hourly_rate_override);
    }

    public function test_net_minutes_never_goes_below_zero(): void
    {
        $shift = Shift::factory()->create(['pause_minutes' => 600]);

        $this->assertSame(0, $shift->netMinutes);
    }

    public function test_open_scope(): void
    {
        $open = Shift::factory()->open()->create();
        Shift::factory()->create();

        $this->assertSame([$open->id], Shift::open()->pluck('id')->all());
    }

    public function test_for_store_and_between_scopes(): void
    {
        $store = Store::factory()->create();
        $inside = Shift::factory()->for($store)->create(['shift_date' => '2026-05-10']);
        Shift::factory()->for($store)->create(['shift_date' => '2026-05-20']);
        Shift::factory()->create(['shift_date' => '2026-05-10']);

        $ids = Shift::forStore($store)->between('2026-05-01', '2026-05-10')->pluck('id')->all();

        $this->assertSame([$inside->id], $ids);
    }

    public function test_ical_uid_is_generated_on_creation(): void
    {
        $shift = Shift::factory()->create();
        $explicit = Shift::factory()->create(['ical_uid' => 'fixed-uid']);

        $this->assertTrue(Str::isUuid($shift->ical_uid));
        $this->assertSame('fixed-uid', $explicit->ical_uid);
        $this->assertSame(0, $shift->ical_sequence);
    }

    public function test_ical_sequence_increments_on_update(): void
    {
        $shift = Shift::factory()->create();

        $shift->update(['notes' => 'a']);
        $shift->update(['notes' => 'b']);

        $this->assertSame(2, $shift->fresh()?->ical_sequence);
    }

    public function test_replace_with_unsaved_shift_takes_over_the_uid(): void
    {
        $old = Shift::factory()->create();
        $old->update(['notes' => 'edited']);
        $uid = $old->ical_uid;
        $new = Shift::factory()->make();

        $old->replaceWith($new);

        $this->assertTrue($new->exists);
        $new = $new->fresh();
        $this->assertNotNull($new);
        $this->assertSame($uid, $new->ical_uid);
        $this->assertGreaterThan($old->ical_sequence, $new->ical_sequence);
        $trashed = Shift::onlyTrashed()->find($old->id);
        $this->assertNotNull($trashed);
        $this->assertSame($new->id, $trashed->replaced_by_id);
        $this->assertNotSame($uid, $trashed->ical_uid);
        $this->assertTrue($new->replaces?->is($old));
        $this->assertTrue($trashed->replacedBy?->is($new));
    }

    public function test_replace_with_existing_shift_keeps_both_uids(): void
    {
        $old = Shift::factory()->create();
        $new = Shift::factory()->create();
        $oldUid = $old->ical_uid;
        $newUid = $new->ical_uid;
        $newSequence = $new->ical_sequence;

        $old->replaceWith($new);

        $trashed = Shift::onlyTrashed()->findOrFail($old->id);
        $this->assertSame($oldUid, $trashed->ical_uid);
        $this->assertSame($new->id, $trashed->replaced_by_id);
        $fresh = $new->fresh();
        $this->assertSame($newUid, $fresh?->ical_uid);
        $this->assertSame($newSequence, $fresh->ical_sequence);
    }

    public function test_replaced_shift_cannot_be_restored_while_replacement_exists(): void
    {
        $old = Shift::factory()->create();
        $new = Shift::factory()->create();
        $old->replaceWith($new);

        $trashed = Shift::onlyTrashed()->findOrFail($old->id);

        try {
            $trashed->restore();
            $this->fail('La restauration aurait dû être refusée.');
        } catch (ShiftRestoreException) {
            $this->assertTrue(Shift::withTrashed()->findOrFail($old->id)->trashed());
        }
    }

    public function test_replaced_shift_can_be_restored_once_replacement_is_deleted(): void
    {
        $old = Shift::factory()->create();
        $new = Shift::factory()->create();
        $old->replaceWith($new);
        $new->fresh()?->delete();

        $trashed = Shift::onlyTrashed()->findOrFail($old->id);
        $trashed->restore();

        $restored = Shift::findOrFail($old->id);
        $this->assertFalse($restored->trashed());
        $this->assertNull($restored->replaced_by_id);
    }

    public function test_plain_deleted_shift_can_be_restored(): void
    {
        $shift = Shift::factory()->create();
        $shift->delete();

        $shift->restore();

        $this->assertFalse($shift->fresh()?->trashed());
    }

    public function test_availability_uses_iso_day_of_week(): void
    {
        $this->assertSame(7, Availability::isoDayOf('2026-11-08')); // dimanche
        $this->assertSame(1, Availability::isoDayOf('2026-11-09')); // lundi

        $sunday = Availability::factory()->create(['day_of_week' => 7]);
        Availability::factory()->create(['day_of_week' => 1]);

        $this->assertSame([$sunday->id], Availability::onDay('2026-11-08')->pluck('id')->all());
    }
}
