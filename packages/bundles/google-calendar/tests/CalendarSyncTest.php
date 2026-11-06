<?php

namespace Kintai\Bundles\GoogleCalendar\Tests;

use App\Actions\Shifts\DeleteShift;
use App\Actions\Shifts\ReplaceShift;
use App\Actions\Shifts\RestoreShift;
use App\Models\ActivityEntry;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Kintai\Bundles\GoogleCalendar\CalendarEventBuilder;
use Kintai\Bundles\GoogleCalendar\CalendarSync;
use Kintai\Bundles\GoogleCalendar\GoogleRedirectUri;
use Kintai\Bundles\GoogleCalendar\Models\CalendarConnection;
use Kintai\Bundles\GoogleCalendar\Models\CalendarEventLink;
use Kintai\Bundles\GoogleCalendar\Models\CalendarSyncQueueItem;
use Kintai\Contracts\Events\ShiftCreated;
use Kintai\Contracts\Events\ShiftUpdated;
use Kintai\Contracts\Planning\TimeOffPeriod;
use Kintai\Contracts\Planning\TimeOffProvider;
use Kintai\Contracts\Settings\Settings;
use RuntimeException;
use Tests\TestCase;

require_once __DIR__.'/GoogleCalendarFixtures.php';

/**
 * Moteur de synchronisation : mise en file par les événements de shift, création, modification, suppression,
 * restauration et remplacement des événements Google, réconciliation périodique, congés approuvés, nouvelles tentatives,
 * révocation.
 */
class CalendarSyncTest extends TestCase
{
    use GoogleCalendarFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootGoogleCalendar();
        $this->configureGoogle();
        $this->fakeGoogle();
    }

    private function sync(): CalendarSync
    {
        return app(CalendarSync::class);
    }

    /**
     * @return list<string>
     */
    private function queued(CalendarConnection $connection): array
    {
        return CalendarSyncQueueItem::query()->where('connection_id', $connection->id)->orderBy('id')->get()
            ->map(fn (CalendarSyncQueueItem $item): string => $item->source_type.':'.$item->source_key)->all();
    }

    private function link(CalendarConnection $connection, string $type, string|int $key): ?CalendarEventLink
    {
        return CalendarEventLink::query()->where(['connection_id' => $connection->id, 'source_type' => $type, 'source_key' => (string) $key])->first();
    }

    /**
     * @param  array<int, list<TimeOffPeriod>>  $periodsByStore
     */
    private function timeOffProvider(array $periodsByStore): void
    {
        $this->app->instance(TimeOffProvider::class, new class($periodsByStore) implements TimeOffProvider
        {
            /** @param array<int, list<TimeOffPeriod>> $periods */
            public function __construct(public array $periods) {}

            public function approvedFor(int $userId, int $storeId, CarbonInterface $from, CarbonInterface $to): array
            {
                return $this->periods[$storeId] ?? [];
            }
        });
    }

    // -- Mise en file par les événements de shift -----------------------------------------------------------------------

    public function test_creating_a_shift_queues_its_source_for_the_linked_employee_only(): void
    {
        $ana = $this->employee();
        $bob = $this->employee();
        $anaLink = $this->connection($ana);
        $bobLink = $this->connection($bob);
        $shift = $this->shiftFor($ana);

        ShiftCreated::dispatch($shift->id);

        $this->assertSame(['shift:'.$shift->id], $this->queued($anaLink));
        $this->assertSame([], $this->queued($bobLink));
        $this->assertCount(0, Http::recorded(), 'Nothing is sent to Google while the request runs.');
    }

    public function test_an_open_shift_or_an_unlinked_employee_queues_nothing(): void
    {
        $ana = $this->employee();
        $this->connection($ana);
        $open = $this->shiftFor(null);
        $other = $this->shiftFor($this->employee());

        ShiftCreated::dispatch($open->id);
        ShiftCreated::dispatch($other->id);

        $this->assertSame(0, CalendarSyncQueueItem::count());
    }

    public function test_a_revoked_link_is_not_queued(): void
    {
        $ana = $this->employee();
        CalendarConnection::factory()->revoked()->create(['user_id' => $ana->id]);

        ShiftCreated::dispatch($this->shiftFor($ana)->id);

        $this->assertSame(0, CalendarSyncQueueItem::count());
    }

    public function test_reassigning_a_shift_queues_it_for_the_new_and_the_previous_employee(): void
    {
        $ana = $this->employee();
        $bob = $this->employee();
        $anaLink = $this->connection($ana);
        $bobLink = $this->connection($bob);
        $shift = $this->shiftFor($bob);

        ShiftUpdated::dispatch($shift->id, $ana->id, true);

        $this->assertSame(['shift:'.$shift->id], $this->queued($anaLink));
        $this->assertSame(['shift:'.$shift->id], $this->queued($bobLink));
    }

    public function test_requeuing_a_source_resets_its_attempts(): void
    {
        $ana = $this->employee();
        $link = $this->connection($ana);
        $shift = $this->shiftFor($ana);
        $this->sync()->enqueue($link, 'shift', (string) $shift->id);
        CalendarSyncQueueItem::query()->update(['attempts' => 5, 'next_attempt_at' => now()->addHour(), 'last_error' => 'boom']);

        ShiftCreated::dispatch($shift->id);

        $this->assertSame(1, CalendarSyncQueueItem::count());
        $item = CalendarSyncQueueItem::firstOrFail();
        $this->assertSame(0, $item->attempts);
        $this->assertNull($item->next_attempt_at);
        $this->assertNull($item->last_error);
    }

    // -- Création, modification, suppression ---------------------------------------------------------------------------

    public function test_a_queued_shift_is_created_in_google_with_its_calendar_then_left_alone_when_unchanged(): void
    {
        $ana = $this->employee();
        $link = CalendarConnection::factory()->create(['user_id' => $ana->id]);
        $type = ShiftType::factory()->create(['name' => 'Morning']);
        $shift = $this->shiftFor($ana, 3, ['shift_type_id' => $type->id, 'notes' => ' Bring the keys ']);
        $this->store->update(['address_street' => '1 Main St', 'address_city' => 'Tokyo']);

        ShiftCreated::dispatch($shift->id);
        $stats = $this->sync()->processQueue();

        $this->assertSame(['processed' => 1, 'failed' => 0, 'revoked' => 0], $stats);
        $this->assertCount(1, $this->sentEnding('POST', '/calendars'));
        $this->assertSame('Kintai', $this->sentEnding('POST', '/calendars')->first()['summary']);
        $this->assertSame('cal-new', $link->fresh()->calendar_id);

        $event = $this->sent('POST', '/events')->sole();
        $date = $shift->shift_date->toDateString();
        $timezone = (string) config('kintai.timezone');
        $this->assertStringContainsString('/calendars/cal-new/events', $event->url());
        $this->assertSame('Morning — Alpha', $event['summary']);
        $this->assertSame('1 Main St, Tokyo', $event['location']);
        $this->assertSame(['dateTime' => $date.'T09:00:00', 'timeZone' => $timezone], $event['start']);
        $this->assertSame(['dateTime' => $date.'T17:00:00', 'timeZone' => $timezone], $event['end']);
        $this->assertSame('opaque', $event['transparency']);
        $this->assertSame('shift:'.$shift->id, $event['extendedProperties']['private']['kintai_source']);
        $this->assertStringContainsString("Break: 30 min\nBring the keys\n", $event['description']);
        $this->assertStringContainsString('/my-shifts/day?date='.$date, $event['description']);

        $stored = $this->link($link, 'shift', $shift->id);
        $this->assertNotNull($stored);
        $this->assertSame(64, strlen($stored->content_hash));
        $this->assertSame(0, CalendarSyncQueueItem::count());
        $this->assertNotNull($link->fresh()->last_sync_at);

        // Même contenu : aucun nouvel appel.
        ShiftCreated::dispatch($shift->id);
        $this->sync()->processQueue();

        $this->assertCount(1, $this->sent('POST', '/events'));
        $this->assertCount(0, $this->sent('PATCH', '/events/'));
    }

    public function test_a_shift_crossing_midnight_ends_the_next_day(): void
    {
        $ana = $this->employee();
        $link = $this->connection($ana);
        $date = $this->today()->addDays(2)->toDateString();
        $shift = $this->shiftFor($ana, 2, ['starts_at' => Shift::localTime($date, '22:00'), 'ends_at' => Shift::localTime($this->today()->addDays(3)->toDateString(), '06:00')]);

        $this->sync()->enqueue($link, 'shift', (string) $shift->id);
        $this->sync()->processQueue();

        $event = $this->sent('POST', '/events')->sole();
        $this->assertSame($date.'T22:00:00', $event['start']['dateTime']);
        $this->assertSame($this->today()->addDays(3)->toDateString().'T06:00:00', $event['end']['dateTime']);
    }

    public function test_a_modified_shift_patches_the_existing_event(): void
    {
        $ana = $this->employee();
        $link = $this->connection($ana);
        $shift = $this->shiftFor($ana);
        $this->sync()->enqueue($link, 'shift', (string) $shift->id);
        $this->sync()->processQueue();
        $first = $this->link($link, 'shift', $shift->id);
        $this->assertNotNull($first);

        $shift->update(['notes' => 'New note']);
        ShiftCreated::dispatch($shift->id);
        $this->sync()->processQueue();

        $patch = $this->sent('PATCH', '/events/')->sole();
        $this->assertStringContainsString('/events/'.$first->external_event_id, $patch->url());
        $this->assertStringContainsString('New note', $patch['description']);
        $this->assertCount(1, $this->sent('POST', '/events'));
        $this->assertNotSame($first->content_hash, $this->link($link, 'shift', $shift->id)?->content_hash);
    }

    public function test_an_event_deleted_by_hand_in_google_is_recreated(): void
    {
        $ana = $this->employee();
        $link = $this->connection($ana);
        $shift = $this->shiftFor($ana);
        $this->sync()->enqueue($link, 'shift', (string) $shift->id);
        $this->sync()->processQueue();

        $this->fakeGoogle(['PATCH /events/' => Http::response(['error' => ['message' => 'Not Found']], 404), 'POST /events' => Http::response(['id' => 'evt-recreated'])]);
        $shift->update(['notes' => 'Changed']);
        $this->sync()->enqueue($link, 'shift', (string) $shift->id);
        $this->sync()->processQueue();

        $this->assertCount(1, $this->sent('PATCH', '/events/'));
        $this->assertCount(1, $this->sent('POST', '/events'));
        $this->assertSame('evt-recreated', $this->link($link, 'shift', $shift->id)?->external_event_id);
    }

    public function test_deleting_a_shift_deletes_its_event_even_though_the_event_carries_no_employee(): void
    {
        $ana = $this->employee();
        $link = $this->connection($ana);
        $shift = $this->shiftFor($ana);
        $this->sync()->enqueue($link, 'shift', (string) $shift->id);
        $this->sync()->processQueue();
        $eventId = $this->link($link, 'shift', $shift->id)?->external_event_id;

        app(DeleteShift::class)->handle($this->owner(), $shift);

        $this->assertSame(['shift:'.$shift->id], $this->queued($link));
        $this->sync()->processQueue();

        $this->assertStringContainsString('/events/'.$eventId, $this->sent('DELETE', '/events/')->sole()->url());
        $this->assertNull($this->link($link, 'shift', $shift->id));
    }

    public function test_a_hard_deleted_shift_is_still_found_through_its_link(): void
    {
        $ana = $this->employee();
        $link = $this->connection($ana);
        $shift = $this->shiftFor($ana);
        $this->sync()->enqueue($link, 'shift', (string) $shift->id);
        $this->sync()->processQueue();

        $shift->forceDelete();
        $this->sync()->shiftChanged($shift->id);

        $this->assertSame(['shift:'.$shift->id], $this->queued($link));
        $this->sync()->processQueue();
        $this->assertCount(1, $this->sent('DELETE', '/events/'));
    }

    public function test_restoring_a_shift_creates_its_event_again(): void
    {
        $ana = $this->employee();
        $link = $this->connection($ana);
        $shift = $this->shiftFor($ana);
        $owner = $this->owner();
        $this->sync()->enqueue($link, 'shift', (string) $shift->id);
        $this->sync()->processQueue();
        app(DeleteShift::class)->handle($owner, $shift);
        $this->sync()->processQueue();
        $this->assertNull($this->link($link, 'shift', $shift->id));

        app(RestoreShift::class)->handle($owner, $shift->fresh() ?? Shift::withTrashed()->findOrFail($shift->id));
        $this->assertSame(['shift:'.$shift->id], $this->queued($link));
        $this->sync()->processQueue();

        $this->assertCount(2, $this->sent('POST', '/events'));
        $this->assertNotNull($this->link($link, 'shift', $shift->id));
    }

    public function test_replacing_a_shift_deletes_the_old_event_and_creates_the_new_one(): void
    {
        $ana = $this->employee();
        $link = $this->connection($ana);
        $old = $this->shiftFor($ana, 3);
        $new = $this->shiftFor($ana, 4);
        $this->sync()->enqueue($link, 'shift', (string) $old->id);
        $this->sync()->processQueue();

        app(ReplaceShift::class)->handle($this->owner(), $old, $new);

        $this->assertEqualsCanonicalizing(['shift:'.$old->id, 'shift:'.$new->id], $this->queued($link));
        $this->sync()->processQueue();

        $this->assertCount(1, $this->sent('DELETE', '/events/'));
        $this->assertNull($this->link($link, 'shift', $old->id));
        $this->assertNotNull($this->link($link, 'shift', $new->id));
    }

    public function test_reassigning_a_shift_removes_the_event_of_the_previous_employee_and_creates_the_new_one(): void
    {
        $ana = $this->employee();
        $bob = $this->employee();
        $anaLink = $this->connection($ana);
        $bobLink = $this->connection($bob);
        $shift = $this->shiftFor($ana);
        $this->sync()->enqueue($anaLink, 'shift', (string) $shift->id);
        $this->sync()->processQueue();

        $shift->update(['user_id' => $bob->id]);
        ShiftUpdated::dispatch($shift->id, $ana->id, true);
        $this->sync()->processQueue();

        $this->assertNull($this->link($anaLink, 'shift', $shift->id));
        $this->assertNotNull($this->link($bobLink, 'shift', $shift->id));
        $this->assertCount(1, $this->sent('DELETE', '/events/'));
    }

    public function test_a_shift_outside_the_window_or_of_someone_else_is_never_sent(): void
    {
        $ana = $this->employee();
        $link = $this->connection($ana);
        $far = $this->shiftFor($ana, 200);
        $past = $this->shiftFor($ana, -30);
        $theirs = $this->shiftFor($this->employee());
        foreach ([$far, $past, $theirs] as $shift) {
            $this->sync()->enqueue($link, 'shift', (string) $shift->id);
        }

        $stats = $this->sync()->processQueue();

        $this->assertSame(3, $stats['processed']);
        $this->assertCount(0, Http::recorded(), 'No event to send: not even a token is requested.');
        $this->assertSame(0, CalendarEventLink::count());
    }

    public function test_event_texts_follow_the_language_of_the_employee(): void
    {
        $ana = $this->employeeOf($this->store, ['locale' => 'ja']);
        $link = $this->connection($ana);
        $shift = $this->shiftFor($ana);
        $before = app()->getLocale();

        $this->sync()->enqueue($link, 'shift', (string) $shift->id);
        $this->sync()->processQueue();

        $event = $this->sent('POST', '/events')->sole();
        $this->assertSame('シフト — Alpha', $event['summary']);
        $this->assertStringContainsString('休憩：30分', $event['description']);
        $this->assertSame($before, app()->getLocale());
    }

    public function test_an_event_never_carries_salary_data(): void
    {
        $ana = $this->employee();
        $link = $this->connection($ana);
        $shift = $this->shiftFor($ana, 3, ['hourly_rate_override' => '98.76', 'net_minutes_override' => 421]);

        $this->sync()->enqueue($link, 'shift', (string) $shift->id);
        $this->sync()->processQueue();

        $this->assertStringNotContainsString('98.76', (string) json_encode($this->sent('POST', '/events')->sole()->data()));
    }

    // -- Nom de l'agenda ------------------------------------------------------------------------------------------------

    public function test_the_calendar_takes_the_organization_name_and_is_renamed_when_it_changes(): void
    {
        app(Settings::class)->set('organization.subtitle', 'Acme Corp');
        $ana = $this->employee();
        $link = CalendarConnection::factory()->create(['user_id' => $ana->id]);

        $this->sync()->ensureCalendarFor($link);
        $this->assertSame('Acme Corp', $this->sentEnding('POST', '/calendars')->sole()['summary']);

        $link->forceFill(['name_mode' => 'store', 'store_id' => $this->store->id])->save();
        $this->sync()->ensureCalendarFor($link->fresh() ?? $link);

        $rename = $this->sentEnding('PATCH', '/calendars/cal-new')->sole();
        $this->assertSame('Alpha', $rename['summary']);
        $this->assertSame('Alpha', $link->fresh()?->calendar_name);
    }

    // -- Réconciliation ------------------------------------------------------------------------------------------------

    public function test_reconciliation_queues_the_window_and_the_orphan_events_and_stamps_the_link(): void
    {
        $ana = $this->employee();
        $link = $this->connection($ana);
        $inside = $this->shiftFor($ana, 5);
        $this->shiftFor($ana, 400);
        $this->shiftFor($this->employee(), 5);
        // Un événement dont la source a disparu (hors fenêtre) est remis en file pour être supprimé.
        CalendarEventLink::create(['connection_id' => $link->id, 'source_type' => 'shift', 'source_key' => '987654', 'external_event_id' => 'evt-old', 'content_hash' => str_repeat('a', 64)]);

        $this->assertTrue($this->sync()->reconcileIsDue($link));
        $queued = $this->sync()->reconcile($link);

        $this->assertSame(2, $queued);
        $this->assertEqualsCanonicalizing(['shift:'.$inside->id, 'shift:987654'], $this->queued($link));
        $this->assertFalse($this->sync()->reconcileIsDue($link->fresh() ?? $link));

        $this->travel(7)->hours();
        $this->assertTrue($this->sync()->reconcileIsDue($link->fresh() ?? $link));

        $this->sync()->processQueue();
        $this->assertSame('/events/evt-old', substr($this->sent('DELETE', '/events/')->sole()->url(), -strlen('/events/evt-old')));
        $this->assertSame(0, CalendarEventLink::where('source_key', '987654')->count());
    }

    // -- Congés approuvés -----------------------------------------------------------------------------------------------

    public function test_approved_time_off_is_synced_as_a_whole_day_event_then_removed_when_it_is_no_longer_approved(): void
    {
        $ana = $this->employee();
        $link = $this->connection($ana);
        $start = $this->today()->addDays(10)->toDateString();
        $end = $this->today()->addDays(11)->toDateString();
        $this->timeOffProvider([$this->store->id => [new TimeOffPeriod('timeoff-12', $start, $end)]]);

        $this->assertSame(1, $this->sync()->reconcile($link));
        $this->assertSame(['timeoff:timeoff-12'], $this->queued($link));
        $this->sync()->processQueue();

        $event = $this->sent('POST', '/events')->sole();
        $this->assertSame('Time off — Alpha', $event['summary']);
        $this->assertSame(['date' => $start], $event['start']);
        $this->assertSame(['date' => $this->today()->addDays(12)->toDateString()], $event['end'], 'The end date of a whole-day event is exclusive.');
        $this->assertSame('transparent', $event['transparency']);
        $this->assertSame('11', $event['colorId']);
        $this->assertNotNull($this->link($link, 'timeoff', 'timeoff-12'));

        // Le congé est refusé ou annulé : la réconciliation suivante supprime l'événement.
        $this->timeOffProvider([]);
        $this->sync()->reconcile($link);
        $this->sync()->processQueue();

        $this->assertCount(1, $this->sent('DELETE', '/events/'));
        $this->assertNull($this->link($link, 'timeoff', 'timeoff-12'));
    }

    public function test_time_off_is_read_for_every_store_of_the_employee_and_counted_once(): void
    {
        $other = Store::factory()->create(['name' => 'Beta']);
        $ana = $this->employee();
        $other->members()->attach($ana);
        $link = $this->connection($ana);
        $day = $this->today()->addDays(6)->toDateString();
        $this->timeOffProvider([
            $this->store->id => [new TimeOffPeriod('timeoff-1', $day, $day)],
            $other->id => [new TimeOffPeriod('timeoff-1', $day, $day), new TimeOffPeriod('timeoff-2', $day, $day)],
        ]);

        $this->assertSame(2, $this->sync()->reconcile($link));
        $this->sync()->processQueue();

        $this->assertCount(2, $this->sent('POST', '/events'));
        $this->assertEqualsCanonicalizing(['timeoff:timeoff-1', 'timeoff:timeoff-2'], CalendarEventLink::all()->map(fn (CalendarEventLink $row): string => $row->source_type.':'.$row->source_key)->all());
    }

    public function test_without_the_timeoff_bundle_no_time_off_event_is_created(): void
    {
        $ana = $this->employee();
        $link = $this->connection($ana);

        $this->assertSame(0, $this->sync()->reconcile($link));
        $this->assertSame(0, CalendarSyncQueueItem::count());
    }

    // -- Pannes passagères et abandon ------------------------------------------------------------------------------------

    public function test_a_rate_limit_postpones_the_item_with_a_growing_delay_then_succeeds(): void
    {
        $ana = $this->employee();
        $link = $this->connection($ana);
        $shift = $this->shiftFor($ana);
        $this->fakeGoogle(['POST /events' => [Http::response(['error' => ['message' => 'Rate Limit']], 429), Http::response(['id' => 'evt-ok'])]]);
        $this->sync()->enqueue($link, 'shift', (string) $shift->id);

        $stats = $this->sync()->processQueue();

        $this->assertSame(['processed' => 0, 'failed' => 1, 'revoked' => 0], $stats);
        $item = CalendarSyncQueueItem::firstOrFail();
        $this->assertSame(1, $item->attempts);
        $this->assertNotNull($item->next_attempt_at);
        $this->assertTrue($item->next_attempt_at->isFuture());
        $this->assertStringContainsString('Rate Limit', (string) $item->last_error);

        // Pas encore dû : rien n'est renvoyé.
        $this->assertSame(0, $this->sync()->processQueue()['processed']);
        $this->assertCount(1, $this->sent('POST', '/events'));

        $this->travel(2)->minutes();
        $this->assertSame(1, $this->sync()->processQueue()['processed']);
        $this->assertSame('evt-ok', $this->link($link, 'shift', $shift->id)?->external_event_id);
        $this->assertSame(0, CalendarSyncQueueItem::count());
    }

    public function test_server_errors_are_retried_until_the_attempts_are_exhausted(): void
    {
        $ana = $this->employee();
        $link = $this->connection($ana);
        $shift = $this->shiftFor($ana);
        $this->fakeGoogle(['POST /events' => Http::response(['error' => ['message' => 'Backend Error']], 503)]);
        $this->sync()->enqueue($link, 'shift', (string) $shift->id);
        CalendarSyncQueueItem::query()->update(['attempts' => CalendarSync::MAX_ATTEMPTS - 2]);

        $this->sync()->processQueue();
        $this->assertSame(CalendarSync::MAX_ATTEMPTS - 1, CalendarSyncQueueItem::firstOrFail()->attempts);

        // Dernier essai : abandon, l'erreur reste visible sur la liaison, la réconciliation suivante retentera.
        CalendarSyncQueueItem::query()->update(['next_attempt_at' => null]);
        $stats = $this->sync()->processQueue();

        $this->assertSame(1, $stats['failed']);
        $this->assertSame(0, CalendarSyncQueueItem::count());
        $this->assertStringContainsString('Backend Error', (string) $link->fresh()?->last_error);
        $this->assertTrue($link->fresh()?->isActive());
    }

    public function test_a_definitive_refusal_drops_the_item_and_keeps_the_link_active(): void
    {
        $ana = $this->employee();
        $link = $this->connection($ana);
        $shift = $this->shiftFor($ana);
        $other = $this->shiftFor($ana, 4);
        $this->fakeGoogle(['POST /events' => [Http::response(['id' => 'evt-2']), Http::response(['error' => ['message' => 'Invalid value']], 400)]]);
        $this->sync()->enqueue($link, 'shift', (string) $other->id);
        $this->sync()->enqueue($link, 'shift', (string) $shift->id);

        $stats = $this->sync()->processQueue();

        $this->assertSame(['processed' => 1, 'failed' => 1, 'revoked' => 0], $stats);
        $this->assertSame(0, CalendarSyncQueueItem::count());
        $this->assertSame('Calendar API: Invalid value', $link->fresh()?->last_error);
    }

    public function test_an_unexpected_error_shows_a_neutral_message_to_the_user(): void
    {
        $ana = $this->employee();
        $link = $this->connection($ana);
        $shift = $this->shiftFor($ana);
        $this->app->instance(CalendarEventBuilder::class, new class($this->app->make(GoogleRedirectUri::class)) extends CalendarEventBuilder
        {
            public function forShift(Shift $shift, User $user): array
            {
                throw new RuntimeException('SQLSTATE[HY000]: secret internal detail');
            }
        });
        $this->sync()->enqueue($link, 'shift', (string) $shift->id);

        $this->sync()->processQueue();

        $this->assertSame('Unexpected error while syncing.', $link->fresh()?->last_error);
        $this->assertSame(0, CalendarSyncQueueItem::count());
    }

    // -- Révocation ---------------------------------------------------------------------------------------------------

    public function test_a_revoked_token_marks_the_link_notifies_the_user_and_stops_the_connection(): void
    {
        $ana = $this->employee();
        $link = $this->connection($ana, ['refresh_token' => 'super-secret-refresh']);
        $first = $this->shiftFor($ana, 3);
        $second = $this->shiftFor($ana, 4);
        $this->fakeGoogle(['POST oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400)]);
        $this->sync()->enqueue($link, 'shift', (string) $first->id);
        $this->sync()->enqueue($link, 'shift', (string) $second->id);

        $stats = $this->sync()->processQueue();

        $this->assertSame(['processed' => 0, 'failed' => 0, 'revoked' => 1], $stats);
        $fresh = $link->fresh();
        $this->assertSame(CalendarConnection::STATUS_REVOKED, $fresh?->status);
        $this->assertSame(0, CalendarSyncQueueItem::count(), 'The remaining items of a revoked link are dropped.');
        $this->assertCount(1, $this->sent('POST', 'oauth2.googleapis.com/token'));

        $this->assertSame(1, DatabaseNotification::query()->where('notifiable_id', $ana->id)->count());
        $entry = ActivityEntry::query()->where('action', 'google_calendar.revoked')->sole();
        $this->assertStringNotContainsString('super-secret-refresh', (string) json_encode($entry->properties));
        $this->assertStringNotContainsString('super-secret-refresh', (string) $fresh?->last_error);

        // Plus rien n'est mis en file pour une liaison révoquée.
        ShiftCreated::dispatch($first->id);
        $this->assertSame(0, CalendarSyncQueueItem::count());
    }

    public function test_an_unreadable_stored_token_revokes_the_link(): void
    {
        $ana = $this->employee();
        $link = $this->connection($ana);
        $shift = $this->shiftFor($ana);
        DB::table('google_calendar_connections')->where('id', $link->id)->update(['refresh_token' => 'not-an-encrypted-payload']);
        $this->sync()->enqueue($link, 'shift', (string) $shift->id);

        $stats = $this->sync()->processQueue();

        $this->assertSame(1, $stats['revoked']);
        $this->assertSame(CalendarConnection::STATUS_REVOKED, $link->fresh()?->status);
        $this->assertCount(0, $this->sent('POST', 'oauth2.googleapis.com/token'));
    }

    public function test_the_refresh_token_is_stored_encrypted_and_never_serialized(): void
    {
        $ana = $this->employee();
        $link = $this->connection($ana, ['refresh_token' => 'plain-refresh-token']);

        $raw = (string) DB::table('google_calendar_connections')->where('id', $link->id)->value('refresh_token');

        $this->assertStringNotContainsString('plain-refresh-token', $raw);
        $this->assertSame('plain-refresh-token', $link->fresh()?->refresh_token);
        $this->assertArrayNotHasKey('refresh_token', $link->toArray());
        $this->assertStringNotContainsString('plain-refresh-token', (string) $link->toJson());
    }

    public function test_the_command_does_nothing_until_the_owner_has_enabled_the_feature(): void
    {
        $ana = $this->employee();
        $link = $this->connection($ana);
        $shift = $this->shiftFor($ana);
        $this->sync()->enqueue($link, 'shift', (string) $shift->id);
        $this->configureGoogle(false);

        $this->artisan('google-calendar:sync')->expectsOutput('{"ok":true,"enabled":false}')->assertSuccessful();
        $this->assertSame(1, CalendarSyncQueueItem::count());
        $this->assertCount(0, Http::recorded());

        $this->configureGoogle();
        $this->artisan('google-calendar:sync')->assertSuccessful();
        $this->assertSame(0, CalendarSyncQueueItem::count());
        $this->assertCount(1, $this->sent('POST', '/events'));
    }

    public function test_the_command_reconciles_a_link_that_is_due_and_processes_the_queue(): void
    {
        $ana = $this->employee();
        $link = $this->connection($ana);
        $shift = $this->shiftFor($ana);

        $this->artisan('google-calendar:sync')->assertSuccessful();

        $this->assertNotNull($this->link($link, 'shift', $shift->id));
        $this->assertNotNull($link->fresh()?->reconciled_at);
    }
}
