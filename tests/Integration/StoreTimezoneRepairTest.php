<?php

declare(strict_types=1);

namespace kintai\Tests\Integration;

use kintai\Tests\Support\CalendarSyncFixture;
use PHPUnit\Framework\TestCase;

/**
 * Scénario réel : des magasins enregistrés en « UTC+9 » et des événements déjà envoyés à Google avec le mauvais contenu
 * (heures décalées de +9 h). La mise à jour du site (migration) doit corriger les magasins ET les agendas déjà synchronisés,
 * sans que l'employé ait quoi que ce soit à faire.
 */
final class StoreTimezoneRepairTest extends TestCase
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

    private function migration(): object
    {
        return require dirname(__DIR__, 2) . '/database/migrations/php/2026_10_24_000000_normalize_store_timezones.php';
    }

    public function testTheUpdateRepairsTheStoresAndTheAlreadySyncedEvents(): void
    {
        // Avant la mise à jour : magasin saisi « UTC+9 », événement envoyé à Google avec une empreinte et un contenu périmés.
        $this->capsule->table('stores')->where('id', 1)->update(['timezone' => 'UTC+9']);
        $shift = $this->shifts->save([
            'store_id' => 1, 'user_id' => $this->alice, 'shift_type_id' => 1, 'shift_date' => $this->day,
            'start_time' => '06:00', 'end_time' => '15:00', 'starts_at' => $this->day . ' 06:00:00', 'ends_at' => $this->day . ' 15:00:00',
        ]);
        $connection = $this->sync->connect($this->alice, 'REFRESH', 'a@example.test');
        $this->sync->processQueue();
        $this->repo->saveLink((int) $connection['id'], 'shift', (int) $shift['id'], 'evt-1', str_repeat('0', 64));
        $this->sync->reconcile($connection);
        $this->assertFalse($this->sync->reconcileIsDue($this->repo->findConnectionByUser($this->alice)), 'réconciliation récente');
        $before = count($this->http->requests);

        // La mise à jour du site.
        $this->migration()->up();

        $this->assertSame('Asia/Tokyo', $this->capsule->table('stores')->where('id', 1)->value('timezone'));
        $fresh = $this->repo->findConnectionByUser($this->alice);
        $this->assertTrue($this->sync->reconcileIsDue($fresh), 'la prochaine exécution de calendar-sync réconcilie tout de suite');

        // Cette exécution : l'événement déjà synchronisé est corrigé (PATCH), pas dupliqué.
        $this->sync->reconcile($fresh);
        $this->sync->processQueue();

        $patches = $this->http->calls('PATCH', '/events/evt-1');
        $this->assertNotEmpty($patches, 'l’événement déjà envoyé à Google est mis à jour');
        $event = json_decode((string) end($patches)['body'], true);
        $this->assertSame($this->day . 'T06:00:00', $event['start']['dateTime']);
        $this->assertSame('Asia/Tokyo', $event['start']['timeZone'], 'fuseau valide : Google place 06:00 à Tokyo, plus 15:00');
        $this->assertSame('Asia/Tokyo', $event['end']['timeZone']);
        $this->assertCount(1, $this->http->calls('POST', '/calendars/cal-1/events'), 'pas de doublon');
        $this->assertGreaterThan($before, count($this->http->requests));
    }

    public function testStoresAlreadyCorrectAreLeftAlone(): void
    {
        $this->capsule->table('stores')->where('id', 1)->update(['timezone' => 'Europe/Paris']);

        $this->migration()->up();

        $this->assertSame('Europe/Paris', $this->capsule->table('stores')->where('id', 1)->value('timezone'));
    }

    public function testAnUnknowableZoneFallsBackToTheInstanceZone(): void
    {
        $this->capsule->table('stores')->where('id', 1)->update(['timezone' => 'Atlantide']);

        $this->migration()->up();

        $this->assertSame(date_default_timezone_get(), $this->capsule->table('stores')->where('id', 1)->value('timezone'));
    }

    public function testRunningTheUpdateTwiceChangesNothingMore(): void
    {
        $this->capsule->table('stores')->where('id', 1)->update(['timezone' => 'UTC+9']);

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame('Asia/Tokyo', $this->capsule->table('stores')->where('id', 1)->value('timezone'));
    }
}
