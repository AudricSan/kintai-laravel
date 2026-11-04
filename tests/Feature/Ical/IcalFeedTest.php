<?php

namespace Tests\Feature\Ical;

use App\Contracts\Planning\TimeOffPeriod;
use App\Contracts\Planning\TimeOffProvider;
use App\Models\IcalToken;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\Store;
use App\Models\User;
use App\Planning\NullTimeOffProvider;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class IcalFeedTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;

    private const TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private Store $store;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-05 10:00:00');
        $this->seedRoles();
        $this->store = Store::factory()->create(['name' => 'Shibuya', 'code' => 'SHB']);
        $this->employee = $this->employeeOf($this->store);
        IcalToken::factory()->forToken(self::TOKEN)->create(['user_id' => $this->employee->id, 'store_id' => $this->store->id]);
    }

    private function feed(?string $token = null, ?int $store = null): TestResponse
    {
        return $this->get('/ical/'.($token ?? self::TOKEN).'/'.($store ?? $this->store->id).'/shifts.ics');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function shift(string $date, array $attributes = []): Shift
    {
        return Shift::factory()->create($attributes + [
            'user_id' => $this->employee->id,
            'store_id' => $this->store->id,
            'shift_date' => $date,
            'starts_at' => Shift::localTime($date, '09:00'),
            'ends_at' => Shift::localTime($date, '17:00'),
            'pause_minutes' => 0,
        ]);
    }

    /**
     * Lignes dépliées du calendrier.
     *
     * @return list<string>
     */
    private function lines(TestResponse $response): array
    {
        return explode("\r\n", rtrim(str_replace("\r\n ", '', (string) $response->getContent()), "\r\n"));
    }

    public function test_a_valid_token_returns_a_calendar_with_one_event_per_shift(): void
    {
        $type = ShiftType::factory()->create(['name' => 'Morning']);
        $shift = $this->shift('2026-11-10', ['shift_type_id' => $type->id, 'notes' => 'Bring keys', 'pause_minutes' => 30]);

        $response = $this->feed()->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
        $lines = $this->lines($response);

        $this->assertSame('BEGIN:VCALENDAR', $lines[0]);
        $this->assertSame('END:VCALENDAR', end($lines));
        $this->assertContains('VERSION:2.0', $lines);
        $this->assertCount(1, array_keys($lines, 'BEGIN:VEVENT'));
        $this->assertContains('UID:'.$shift->ical_uid, $lines);
        $this->assertContains('SEQUENCE:0', $lines);
        $this->assertContains('DTSTART:20261110T090000Z', $lines);
        $this->assertContains('DTEND:20261110T170000Z', $lines);
        $this->assertContains('SUMMARY:Morning — Shibuya', $lines);
        $this->assertContains('LOCATION:Shibuya', $lines);
        $this->assertContains('STATUS:CONFIRMED', $lines);
        $this->assertContains('DESCRIPTION:Break: 30 min\nBring keys', $lines);
    }

    public function test_events_are_published_in_utc_whatever_the_instance_timezone(): void
    {
        config(['kintai.timezone' => 'Asia/Tokyo']);
        $this->shift('2026-11-10', [
            'starts_at' => Shift::localTime('2026-11-10', '09:00'),
            'ends_at' => Shift::localTime('2026-11-10', '17:00'),
        ]);

        $lines = $this->lines($this->feed());

        $this->assertContains('DTSTART:20261110T000000Z', $lines);
        $this->assertContains('DTEND:20261110T080000Z', $lines);
    }

    public function test_sequence_follows_ical_sequence(): void
    {
        $shift = $this->shift('2026-11-10');
        $shift->update(['notes' => 'changed']);
        $shift->update(['notes' => 'changed again']);

        $this->assertContains('SEQUENCE:2', $this->lines($this->feed()));
    }

    public function test_only_the_employee_shifts_of_this_store_are_published(): void
    {
        $mine = $this->shift('2026-11-10');
        $other = Store::factory()->create();
        $this->shift('2026-11-11', ['store_id' => $other->id]);
        $this->shift('2026-11-12', ['user_id' => $this->employeeOf($this->store)->id]);
        $this->shift('2026-11-13', ['user_id' => null]);

        $lines = $this->lines($this->feed());

        $this->assertCount(1, array_keys($lines, 'BEGIN:VEVENT'));
        $this->assertContains('UID:'.$mine->ical_uid, $lines);
    }

    public function test_the_feed_covers_60_days_back_and_365_days_ahead(): void
    {
        $oldIn = $this->shift('2026-09-06');   // 60 jours avant
        $oldOut = $this->shift('2026-09-05');  // 61 jours avant
        $farIn = $this->shift('2027-11-05');   // 365 jours après
        $farOut = $this->shift('2027-11-06');  // 366 jours après

        $content = (string) $this->feed()->getContent();

        $this->assertStringContainsString($oldIn->ical_uid, $content);
        $this->assertStringNotContainsString($oldOut->ical_uid, $content);
        $this->assertStringContainsString($farIn->ical_uid, $content);
        $this->assertStringNotContainsString($farOut->ical_uid, $content);
    }

    public function test_a_shift_deleted_less_than_30_days_ago_is_published_as_cancelled(): void
    {
        $shift = $this->shift('2026-11-10', ['ical_sequence' => 3]);
        $shift->delete();

        $lines = $this->lines($this->feed());

        $this->assertContains('UID:'.$shift->ical_uid, $lines);
        $this->assertContains('STATUS:CANCELLED', $lines);
        $this->assertNotContains('STATUS:CONFIRMED', $lines);
        // La séquence monte pour que l'agenda retienne l'annulation.
        $this->assertContains('SEQUENCE:4', $lines);
    }

    public function test_a_shift_deleted_more_than_30_days_ago_is_no_longer_published(): void
    {
        $recent = $this->shift('2026-11-10');
        $recent->delete();
        $old = $this->shift('2026-11-11');
        $old->delete();
        Shift::withTrashed()->whereKey($old->id)->update(['deleted_at' => Carbon::now()->subDays(31)]);
        Shift::withTrashed()->whereKey($recent->id)->update(['deleted_at' => Carbon::now()->subDays(29)]);

        $content = (string) $this->feed()->getContent();

        $this->assertStringContainsString($recent->ical_uid, $content);
        $this->assertStringNotContainsString($old->ical_uid, $content);
    }

    public function test_a_replacement_keeps_the_uid_as_a_modification_and_the_old_shift_is_cancelled(): void
    {
        $old = $this->shift('2026-11-10');
        $uid = $old->ical_uid;
        $new = Shift::fromLocalTimes('2026-11-10', '10:00', '18:00', ['user_id' => $this->employee->id, 'store_id' => $this->store->id, 'pause_minutes' => 0]);
        $old->replaceWith($new);

        $lines = $this->lines($this->feed());

        $this->assertCount(1, array_keys($lines, 'UID:'.$uid));
        $this->assertCount(2, array_keys($lines, 'BEGIN:VEVENT'));
        $this->assertContains('SEQUENCE:1', $lines);
        $this->assertContains('DTSTART:20261110T100000Z', $lines);
        $this->assertContains('STATUS:CONFIRMED', $lines);
        $this->assertContains('STATUS:CANCELLED', $lines);
    }

    public function test_a_shift_replaced_by_an_existing_shift_is_published_as_cancelled(): void
    {
        $old = $this->shift('2026-11-10');
        $replacement = $this->shift('2026-11-10', ['user_id' => $this->employeeOf($this->store)->id]);
        $old->replaceWith($replacement);

        $lines = $this->lines($this->feed());

        $this->assertContains('UID:'.$old->ical_uid, $lines);
        $this->assertSame(['STATUS:CANCELLED'], array_values(array_filter($lines, fn (string $l): bool => str_starts_with($l, 'STATUS:'))));
    }

    public function test_text_is_escaped_and_long_lines_are_folded_to_75_octets(): void
    {
        $notes = 'Semi;colon, comma \\ backslash'."\n".str_repeat('勤務メモ', 60);
        $this->shift('2026-11-10', ['notes' => $notes]);

        $response = $this->feed();
        $raw = (string) $response->getContent();

        foreach (explode("\r\n", $raw) as $physical) {
            $this->assertLessThanOrEqual(75, strlen($physical));
            $this->assertTrue(mb_check_encoding($physical, 'UTF-8'));
        }
        $description = collect($this->lines($response))->first(fn (string $l): bool => str_starts_with($l, 'DESCRIPTION:'));
        $this->assertStringStartsWith('DESCRIPTION:Semi\;colon\, comma \\\\ backslash\n勤務メモ', $description);
    }

    public function test_time_off_is_published_as_all_day_events_through_the_core_interface(): void
    {
        $this->app->bind(TimeOffProvider::class, fn () => new class implements TimeOffProvider
        {
            public function approvedFor(User $user, Store $store, CarbonInterface $from, CarbonInterface $to): array
            {
                return [new TimeOffPeriod('timeoff-7', '2026-11-20', '2026-11-22')];
            }
        });

        $lines = $this->lines($this->feed());

        $this->assertContains('UID:timeoff-7', $lines);
        $this->assertContains('DTSTART;VALUE=DATE:20261120', $lines);
        $this->assertContains('DTEND;VALUE=DATE:20261123', $lines);
        $this->assertContains('TRANSP:TRANSPARENT', $lines);
    }

    public function test_without_a_time_off_bundle_the_feed_has_no_time_off(): void
    {
        $this->assertInstanceOf(NullTimeOffProvider::class, app(TimeOffProvider::class));
        $this->assertStringNotContainsString('VALUE=DATE', (string) $this->feed()->getContent());
    }

    public function test_the_feed_is_in_the_language_of_the_employee(): void
    {
        $this->employee->forceFill(['locale' => 'fr'])->save();
        $this->shift('2026-11-10', ['pause_minutes' => 45]);

        $this->assertContains('DESCRIPTION:Pause : 45 min', $this->lines($this->feed()));
    }

    public function test_the_response_creates_no_session_and_sets_no_cookie(): void
    {
        $response = $this->feed()->assertOk();

        $this->assertSame([], $response->headers->getCookies());
        $this->assertNull($response->headers->get('Set-Cookie'));
        $this->assertGuest();
    }

    public function test_a_client_with_the_same_etag_receives_a_304(): void
    {
        $this->shift('2026-11-10');
        $etag = $this->feed()->assertOk()->headers->get('ETag');

        $this->assertNotNull($etag);
        $this->get('/ical/'.self::TOKEN.'/'.$this->store->id.'/shifts.ics', ['If-None-Match' => $etag])->assertStatus(304);
    }

    public function test_every_failure_returns_the_same_404(): void
    {
        $other = Store::factory()->create();
        $inactive = $this->employeeOf($this->store, ['is_active' => false]);
        IcalToken::factory()->forToken('inactive-user-token')->create(['user_id' => $inactive->id, 'store_id' => $this->store->id]);
        $gone = $this->employeeOf($this->store);
        IcalToken::factory()->forToken('anonymized-user-token')->create(['user_id' => $gone->id, 'store_id' => $this->store->id]);
        $gone->delete();
        $left = $this->employeeOf($this->store);
        IcalToken::factory()->forToken('left-store-token')->create(['user_id' => $left->id, 'store_id' => $this->store->id]);
        $left->stores()->detach($this->store->id);
        $deactivated = $this->employeeOf($this->store);
        IcalToken::factory()->forToken('inactive-membership-token')->create(['user_id' => $deactivated->id, 'store_id' => $this->store->id]);
        $deactivated->stores()->updateExistingPivot($this->store->id, ['is_active' => false]);
        $closed = Store::factory()->create();
        $closedMember = $this->employeeOf($closed);
        IcalToken::factory()->forToken('closed-store-token')->create(['user_id' => $closedMember->id, 'store_id' => $closed->id]);
        $closed->delete();

        $responses = [
            $this->feed('unknown-token'),
            $this->feed(self::TOKEN, $other->id),
            $this->feed(self::TOKEN, 999999),
            $this->feed('inactive-user-token'),
            $this->feed('anonymized-user-token'),
            $this->feed('left-store-token'),
            $this->feed('inactive-membership-token'),
            $this->feed('closed-store-token', $closed->id),
            $this->get('/ical/'.self::TOKEN.'/not-a-number/shifts.ics'),
        ];

        foreach ($responses as $response) {
            $response->assertNotFound();
            $this->assertSame($responses[0]->getContent(), $response->getContent());
            $this->assertSame($responses[0]->headers->get('Content-Type'), $response->headers->get('Content-Type'));
            $this->assertSame([], $response->headers->getCookies());
            $this->assertStringNotContainsString('BEGIN:VCALENDAR', (string) $response->getContent());
        }
    }

    public function test_the_feed_is_rate_limited(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->feed('unknown-token')->assertNotFound();
        }

        $this->feed('unknown-token')->assertStatus(429);
    }
}
