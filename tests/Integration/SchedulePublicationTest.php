<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use App\Models\Shift;
use App\Repositories\DatabaseStoreRepository;
use App\Services\NotificationService;
use App\Services\SchedulePublication;
use App\Services\ShiftChangeNotifier;
use PHPUnit\Framework\TestCase;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

/**
 * Publication du planning par magasin, sur le vrai schéma (migrations sur SQLite en mémoire) : brouillons cachés aux
 * employés, publication qui prévient chaque employé une seule fois, activation qui ne cache rien de déjà planifié.
 */
final class SchedulePublicationTest extends TestCase
{
    private Capsule $capsule;
    private DatabaseStoreRepository $stores;
    /** @var NotificationService&object{sent: list<array<string, mixed>>} */
    private NotificationService $notifs;
    private SchedulePublication $publication;
    private int $store;
    private int $alice;
    private int $bob;

    protected function setUp(): void
    {
        $this->capsule = kintai_test_database();

        kintai_test_migrate();

        // Les écritures de shifts n'annoncent rien ici (pas d'agenda externe à synchroniser).
        ShiftChangeNotifier::override(new ShiftChangeNotifier(new \App\Extension\Events(), static function (): void {}));

        $now = date('Y-m-d H:i:s');
        foreach (['alice', 'bob'] as $name) {
            $this->capsule->table('users')->insert([
                'first_name' => ucfirst($name), 'last_name' => 'Test', 'display_name' => ucfirst($name),
                'email' => $name . '@kintai.test', 'password_hash' => 'x', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $this->alice = (int) $this->capsule->table('users')->where('email', 'alice@kintai.test')->value('id');
        $this->bob   = (int) $this->capsule->table('users')->where('email', 'bob@kintai.test')->value('id');
        $this->capsule->table('stores')->insert(['name' => 'Shibuya', 'code' => 'SHB', 'timezone' => 'Asia/Tokyo', 'created_at' => $now, 'updated_at' => $now]);
        $this->store = (int) $this->capsule->table('stores')->where('code', 'SHB')->value('id');
        $this->stores = new DatabaseStoreRepository();
        $this->notifs = new class extends NotificationService {
            /** @var list<array<string, mixed>> */
            public array $sent = [];

            public function __construct() {}

            public function notify(int $userId, string $type, string $bodyKey, array $replace = [], ?int $referenceId = null, ?string $link = null): void
            {
                $this->sent[] = ['user' => $userId, 'type' => $type, 'body' => $bodyKey, 'replace' => $replace, 'link' => $link];
            }
        };
        $this->publication = new SchedulePublication($this->stores, $this->notifs);
    }

    protected function tearDown(): void
    {
        ShiftChangeNotifier::override(null);
    }

    private function shift(int $userId, string $date, array $extra = []): array
    {
        return Shift::put($extra + [
            'store_id' => $this->store, 'user_id' => $userId, 'shift_date' => $date,
            'start_time' => '09:00', 'end_time' => '17:00', 'starts_at' => $date . ' 09:00:00', 'ends_at' => $date . ' 17:00:00',
        ]);
    }

    private function day(int $offset): string
    {
        return date('Y-m-d', strtotime(($offset >= 0 ? '+' : '') . $offset . ' days'));
    }

    public function testWithoutPublicationEveryShiftIsVisible(): void
    {
        $shift = $this->shift($this->alice, $this->day(30));

        $this->assertFalse($this->publication->isEnabled($this->store));
        $this->assertTrue($this->publication->isPublished($shift));
        $this->assertCount(1, $this->publication->visibleToStaff([$shift]));
    }

    public function testTurningPublicationOnKeepsEverythingAlreadyScheduledVisible(): void
    {
        $far = $this->shift($this->alice, $this->day(20));

        $this->publication->configure($this->store, true);

        $this->assertTrue($this->publication->isEnabled($this->store));
        $this->assertSame($this->day(20), $this->publication->publishedUntil($this->store));
        $this->assertTrue($this->publication->isPublished($far), 'aucun shift déjà planifié ne disparaît');
        $this->assertFalse($this->publication->isPublished($this->shift($this->alice, $this->day(21))), 'le suivant est un brouillon');
    }

    public function testWithNothingPlannedPublicationStartsFromToday(): void
    {
        $this->publication->configure($this->store, true);

        $this->assertSame($this->day(0), $this->publication->publishedUntil($this->store));
    }

    public function testDraftsAreHiddenFromStaff(): void
    {
        $this->publication->configure($this->store, true);
        $published = $this->shift($this->alice, $this->day(0));
        $draft     = $this->shift($this->alice, $this->day(5));

        $visible = $this->publication->visibleToStaff([$published, $draft]);

        $this->assertSame([(int) $published['id']], array_map(fn(array $s): int => (int) $s['id'], $visible));
    }

    public function testPublishingNotifiesEachEmployeeOnceWithTheirShiftCount(): void
    {
        $this->publication->configure($this->store, true);
        $this->shift($this->alice, $this->day(2));
        $this->shift($this->alice, $this->day(3));
        $this->shift($this->bob, $this->day(4));
        $this->shift($this->bob, $this->day(9)); // au-delà de la date publiée : reste un brouillon
        $this->shift(0, $this->day(3), ['user_id' => null, 'is_open' => 1]); // shift ouvert : personne à prévenir

        $result = $this->publication->publish($this->store, $this->day(7));

        $this->assertSame(['published' => 4, 'notified' => 2], $result);
        $this->assertSame($this->day(7), $this->publication->publishedUntil($this->store));
        $byUser = [];
        foreach ($this->notifs->sent as $n) {
            $byUser[$n['user']] = $n;
        }
        $this->assertCount(2, $this->notifs->sent, 'une notification par employé, pas une par shift');
        $this->assertSame('schedule_published', $byUser[$this->alice]['type']);
        $this->assertSame('2', $byUser[$this->alice]['replace']['count']);
        $this->assertSame('1', $byUser[$this->bob]['replace']['count']);
        $this->assertSame('Shibuya', $byUser[$this->bob]['replace']['store']);
        $this->assertSame('/employee/shifts/day?start=' . $this->day(2) . '&view=week', $byUser[$this->alice]['link']);
    }

    public function testThePublicationIsStoredForLaterRequests(): void
    {
        $this->publication->configure($this->store, true);
        $this->publication->publish($this->store, $this->day(7));

        $fresh = new SchedulePublication($this->stores, $this->notifs);

        $this->assertSame($this->day(7), $fresh->publishedUntil($this->store));
    }

    public function testPublishingAgainOnlyAnnouncesTheNewDays(): void
    {
        $this->publication->configure($this->store, true);
        $this->shift($this->alice, $this->day(2));
        $this->publication->publish($this->store, $this->day(7));
        $this->notifs->sent = [];
        $this->shift($this->alice, $this->day(10));

        $result = $this->publication->publish($this->store, $this->day(14));

        $this->assertSame(['published' => 1, 'notified' => 1], $result);
        $this->assertSame('1', $this->notifs->sent[0]['replace']['count']);
    }

    public function testAnEarlierDateNeverUnpublishes(): void
    {
        $this->publication->configure($this->store, true);
        $this->publication->publish($this->store, $this->day(7));

        $result = $this->publication->publish($this->store, $this->day(3));

        $this->assertSame(['published' => 0, 'notified' => 0], $result);
        $this->assertSame($this->day(7), $this->publication->publishedUntil($this->store));
    }

    public function testPublishingRequiresPublicationToBeOn(): void
    {
        $this->expectException(\LogicException::class);
        $this->publication->publish($this->store, $this->day(7));
    }

    public function testTurningPublicationOffShowsEverythingAgain(): void
    {
        $this->publication->configure($this->store, true);
        $draft = $this->shift($this->alice, $this->day(30));
        $this->assertFalse($this->publication->isPublished($draft));

        $this->publication->configure($this->store, false);

        $this->assertTrue($this->publication->isPublished($draft));
    }
}
