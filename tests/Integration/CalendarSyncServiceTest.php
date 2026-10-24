<?php

declare(strict_types=1);

namespace kintai\Tests\Integration;

use kintai\Core\Container;
use kintai\Core\Services\Calendar\CalendarEventBuilder;
use kintai\Core\Services\TranslationService;
use kintai\Tests\Support\CalendarSyncFixture;
use PHPUnit\Framework\TestCase;

/**
 * Synchronisation Kintai → Google Calendar de bout en bout : vrai schéma (migrations), vrais dépôts, transport HTTP
 * factice qui enregistre les appels envoyés à Google.
 */
final class CalendarSyncServiceTest extends TestCase
{
    use CalendarSyncFixture;

    protected function setUp(): void
    {
        $this->setUpCalendarFixture();
    }

    protected function tearDown(): void
    {
        $this->tearDownCalendarFixture();
    }

    private function shift(int $userId, array $extra = []): array
    {
        return $this->shifts->save($extra + [
            'store_id' => 1, 'user_id' => $userId, 'shift_type_id' => 1, 'shift_date' => $this->day,
            'start_time' => '09:00', 'end_time' => '17:00', 'starts_at' => $this->day . ' 09:00:00', 'ends_at' => $this->day . ' 17:00:00',
        ]);
    }

    private function connect(int $userId): array
    {
        return $this->sync->connect($userId, 'REFRESH-' . $userId, 'user@example.com');
    }

    public function testFirstSyncCreatesTheCompanyCalendarAndOneEventPerShift(): void
    {
        $shift = $this->shift($this->alice, ['notes' => 'Ouverture']);
        $this->connect($this->alice);

        $stats = $this->sync->processQueue();

        $this->assertSame(1, $stats['processed']);
        $created = $this->http->callsEndingWith('POST', '/calendars');
        $this->assertCount(1, $created);
        $calendar = json_decode((string) $created[0]['body'], true);
        $this->assertSame('Sakura Co.', $calendar['summary'], 'agenda nommé d’après l’entreprise');
        $this->assertSame('Asia/Tokyo', $calendar['timeZone']);

        $events = $this->http->calls('POST', '/calendars/cal-1/events');
        $this->assertCount(1, $events);
        $event = json_decode((string) $events[0]['body'], true);
        $this->assertSame('Matin — Shibuya', $event['summary']);
        $this->assertSame($this->day . 'T09:00:00', $event['start']['dateTime']);
        $this->assertSame('Asia/Tokyo', $event['start']['timeZone']);
        $this->assertStringContainsString('Ouverture', $event['description']);
        $this->assertStringContainsString('https://kintai.test/employee/shifts/day?date=' . $this->day, $event['description']);
        $this->assertArrayNotHasKey('salary', $event);

        $this->assertSame('evt-1', $this->repo->findLink(1, 'shift', (int) $shift['id'])['external_event_id']);
        $this->assertSame('active', $this->repo->findConnectionByUser($this->alice)['status']);
        $this->assertNotNull($this->repo->findConnectionByUser($this->alice)['last_sync_at']);
    }

    public function testTheRefreshTokenIsNeverStoredInClear(): void
    {
        $this->connect($this->alice);

        $stored = (string) $this->repo->findConnectionByUser($this->alice)['refresh_token'];

        $this->assertStringNotContainsString('REFRESH-', $stored);
        $this->assertStringStartsWith('enc1:', $stored);
    }

    public function testAnUnchangedShiftSendsNothingTheSecondTime(): void
    {
        $this->shift($this->alice);
        $connection = $this->connect($this->alice);
        $this->sync->processQueue();
        $before = count($this->http->requests);

        $this->sync->reconcile($connection);
        $this->sync->processQueue();

        $calls = array_slice($this->http->requests, $before);
        $this->assertSame([], array_filter($calls, fn(array $r) => str_contains($r['url'], '/events')), 'empreinte identique : aucun appel à l’API Agenda');
    }

    public function testAModifiedShiftPatchesTheSameEvent(): void
    {
        $shift = $this->shift($this->alice);
        $this->connect($this->alice);
        $this->sync->processQueue();

        $saved = $this->shifts->save(['id' => $shift['id'], 'end_time' => '18:00']);
        $this->sync->shiftChanged('saved', $saved, $shift);
        $this->sync->processQueue();

        $patches = $this->http->calls('PATCH', '/events/evt-1');
        $this->assertCount(1, $patches);
        $this->assertSame($this->day . 'T18:00:00', json_decode((string) $patches[0]['body'], true)['end']['dateTime']);
        $this->assertCount(1, $this->http->calls('POST', '/calendars/cal-1/events'), 'pas de doublon');
    }

    public function testAShiftTakenFromAnEmployeeIsRemovedFromThatEmployeeCalendarOnly(): void
    {
        $shift = $this->shift($this->alice);
        $this->connect($this->alice);
        $this->connect($this->bob);
        $this->sync->processQueue();
        $this->assertCount(1, $this->http->calls('POST', '/calendars/cal-1/events'));

        $moved = $this->shifts->save(['id' => $shift['id'], 'user_id' => $this->bob]);
        $this->sync->shiftChanged('saved', $moved, $shift);
        $this->sync->processQueue();

        $this->assertCount(1, $this->http->calls('DELETE', '/events/evt-1'), 'retiré de l’agenda d’Alice');
        $this->assertNull($this->repo->findLink(1, 'shift', (int) $shift['id']));
        $this->assertNotNull($this->repo->findLink(2, 'shift', (int) $shift['id']), 'ajouté à celui de Bob');
    }

    public function testADeletedShiftDeletesTheEvent(): void
    {
        $shift = $this->shift($this->alice);
        $this->connect($this->alice);
        $this->sync->processQueue();

        $this->shifts->delete((int) $shift['id']);
        $this->sync->shiftChanged('deleted', $shift, null);
        $this->sync->processQueue();

        $this->assertCount(1, $this->http->calls('DELETE', '/events/evt-1'));
        $this->assertNull($this->repo->findLink(1, 'shift', (int) $shift['id']));
    }

    public function testAnEventDeletedByHandOnGoogleIsRecreated(): void
    {
        $shift = $this->shift($this->alice);
        $this->connect($this->alice);
        $this->sync->processQueue();
        $this->http->when('PATCH', '/events/evt-1', ['status' => 404, 'body' => '{}']);

        $saved = $this->shifts->save(['id' => $shift['id'], 'notes' => 'changé']);
        $this->sync->shiftChanged('saved', $saved, $shift);
        $this->sync->processQueue();

        $this->assertCount(2, $this->http->calls('POST', '/calendars/cal-1/events'));
        $this->assertSame('evt-2', $this->repo->findLink(1, 'shift', (int) $shift['id'])['external_event_id']);
    }

    public function testShiftsOutsideTheSlidingWindowAreIgnored(): void
    {
        $this->shift($this->alice, ['shift_date' => date('Y-m-d', strtotime('+200 days'))]);
        $this->shift($this->alice, ['shift_date' => date('Y-m-d', strtotime('-30 days'))]);
        $this->connect($this->alice);

        $this->sync->processQueue();

        $this->assertCount(0, $this->http->calls('POST', '/events'));
    }

    public function testApprovedTimeoffIsSyncedWithItsOwnColourAndRemovedWhenNoLongerApproved(): void
    {
        $start = date('Y-m-d', strtotime('+5 days'));
        $this->absences = [['id' => 7, 'user_id' => $this->alice, 'store_id' => 1, 'start_date' => $start, 'end_date' => date('Y-m-d', strtotime('+6 days')), 'type' => 'paid', 'status' => 'approved']];
        $connection = $this->connect($this->alice);
        $this->sync->processQueue();

        $event = json_decode((string) $this->http->calls('POST', '/calendars/cal-1/events')[0]['body'], true);
        $this->assertSame(CalendarEventBuilder::TIMEOFF_COLOR_ID, $event['colorId']);
        $this->assertSame(['date' => $start], $event['start']);
        $this->assertSame(['date' => date('Y-m-d', strtotime('+7 days'))], $event['end'], 'fin exclusive pour un événement journée entière');

        $this->absences[0]['status'] = 'rejected';
        $this->sync->reconcile($connection);
        $this->sync->processQueue();

        $this->assertCount(1, $this->http->calls('DELETE', '/events/evt-1'));
    }

    public function testStoreColourIsStableAndNeverTheTimeoffColour(): void
    {
        $builder = new CalendarEventBuilder(Container::getInstance()->make(TranslationService::class));
        $seen = [];
        for ($storeId = 1; $storeId <= 30; $storeId++) {
            $event = $builder->forShift(['id' => 1, 'shift_date' => $this->day, 'start_time' => '09:00', 'end_time' => '10:00'], ['id' => $storeId, 'name' => 'S'], null, ['language' => 'fr'], null)['event'];
            $seen[$event['colorId']] = true;
        }
        $this->assertArrayNotHasKey(CalendarEventBuilder::TIMEOFF_COLOR_ID, $seen);
        $this->assertCount(10, $seen);
    }

    public function testARevokedLinkIsMarkedNotifiedAndNoLongerRetried(): void
    {
        $this->shift($this->alice);
        // Google refuse déjà le jeton au moment de lier (l'échec de création de l'agenda est sans conséquence) puis à la synchro.
        $this->http->when('POST', '/token', ['status' => 400, 'body' => json_encode(['error' => 'invalid_grant'])]);
        $this->connect($this->alice);
        $stats = $this->sync->processQueue();

        $this->assertSame(1, $stats['revoked']);
        $this->assertSame([[$this->alice, 'calendar_sync_error', 'notif_calendar_sync_error_body']], $this->notifications->sent);
        $this->assertSame('revoked', $this->repo->findConnectionByUser($this->alice)['status']);
        $this->assertSame([], $this->repo->dueQueue(10), 'plus rien à rejouer pour une liaison révoquée');
    }

    public function testATemporaryFailureIsRetriedLaterWithBackoff(): void
    {
        $this->shift($this->alice);
        $this->connect($this->alice);
        $this->http->when('POST', '/calendars/cal-1/events', ['status' => 503, 'body' => '{}']);

        $stats = $this->sync->processQueue();

        $this->assertSame(1, $stats['failed']);
        $this->assertSame([], $this->repo->dueQueue(10), 'reporté, pas immédiatement rejoué');
        $item = $this->capsule->table('calendar_sync_queue')->first();
        $this->assertSame(1, (int) $item->attempts);
        $this->assertGreaterThan(date('Y-m-d H:i:s'), $item->next_attempt_at);
        $this->assertSame('active', $this->repo->findConnectionByUser($this->alice)['status']);
    }

    public function testCalendarIsRenamedWhenTheCompanyNameChanges(): void
    {
        $this->shift($this->alice);
        $this->connect($this->alice);
        $this->sync->processQueue();

        $this->appSettings->setMany(['app_subtitle' => 'Sakura Holding']);
        $this->sync->shiftChanged('saved', ['id' => 1, 'user_id' => $this->alice], null);
        $this->sync->processQueue();

        $renames = $this->http->calls('PATCH', '/calendars/cal-1');
        $this->assertCount(1, $renames);
        $this->assertSame(['summary' => 'Sakura Holding'], json_decode((string) $renames[0]['body'], true));
    }

    public function testTheCalendarCanBeNamedAfterAStoreInstead(): void
    {
        $this->shift($this->alice);
        $this->sync->connect($this->alice, 'R', null, ['calendar_name_mode' => 'store', 'store_id' => 1]);

        $this->sync->processQueue();

        $this->assertSame('Shibuya', json_decode((string) $this->http->callsEndingWith('POST', '/calendars')[0]['body'], true)['summary']);
    }

    public function testNothingIsQueuedForAnEmployeeWithoutAConnection(): void
    {
        $this->sync->shiftChanged('saved', $this->shift($this->bob), null);

        $this->assertSame([], $this->repo->dueQueue(10));
    }

    public function testBurstOfWritesOnTheSameShiftCollapsesIntoOneQueueRow(): void
    {
        $shift = $this->shift($this->alice);
        $this->connect($this->alice);
        $this->sync->processQueue();

        for ($i = 0; $i < 5; $i++) {
            $this->sync->shiftChanged('saved', $shift, $shift);
        }

        $this->assertCount(1, $this->repo->dueQueue(10));
    }

    public function testDisconnectCanRemoveTheEventsThenRevokesAndPurgesEverything(): void
    {
        $shift = $this->shift($this->alice);
        $this->connect($this->alice);
        $this->sync->processQueue();

        $this->sync->disconnect($this->alice, true);

        $this->assertCount(1, $this->http->calls('DELETE', '/events/evt-1'));
        $this->assertCount(1, $this->http->calls('POST', '/revoke'));
        $this->assertNull($this->repo->findConnectionByUser($this->alice));
        $this->assertNull($this->repo->findLink(1, 'shift', (int) $shift['id']));
        $this->assertSame(0, $this->capsule->table('calendar_sync_queue')->count());
    }

    public function testDisconnectKeepsTheEventsByDefaultAndSurvivesAnUnreachableGoogle(): void
    {
        $this->shift($this->alice);
        $this->connect($this->alice);
        $this->sync->processQueue();
        $this->http->when('POST', '/revoke', ['status' => 0, 'body' => '']);

        $this->sync->disconnect($this->alice);

        $this->assertCount(0, $this->http->calls('DELETE'));
        $this->assertNull($this->repo->findConnectionByUser($this->alice));
    }

    public function testReconcileRemovesEventsWhoseSourceVanished(): void
    {
        $shift = $this->shift($this->alice);
        $connection = $this->connect($this->alice);
        $this->sync->processQueue();

        // Suppression par un chemin qui n'annoncerait rien (import, SQL direct) : le filet de réconciliation la rattrape.
        $this->capsule->table('shifts')->where('id', $shift['id'])->delete();
        $this->sync->reconcile($connection);
        $this->sync->processQueue();

        $this->assertCount(1, $this->http->calls('DELETE', '/events/evt-1'));
    }

    public function testReconcileIsDueOnlyEverySixHours(): void
    {
        $connection = $this->connect($this->alice);
        $fresh = $this->repo->findConnection((int) $connection['id']);
        $this->assertFalse($this->sync->reconcileIsDue($fresh), 'connect() vient de réconcilier');

        $this->repo->updateConnection((int) $connection['id'], ['options' => json_encode(['reconciled_at' => time() - 7 * 3600])]);
        $this->assertTrue($this->sync->reconcileIsDue($this->repo->findConnection((int) $connection['id'])));
    }

    public function testDeleteAllForUserPurgesLinksAndQueueToo(): void
    {
        $this->shift($this->alice);
        $this->connect($this->alice);
        $this->sync->processQueue();
        $this->sync->shiftChanged('saved', ['id' => 1, 'user_id' => $this->alice], null);

        $this->repo->deleteAllForUser($this->alice);

        foreach (['calendar_connections', 'calendar_event_links', 'calendar_sync_queue'] as $table) {
            $this->assertSame(0, $this->capsule->table($table)->count(), $table);
        }
    }
}
