<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Container\Container;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Repositories\DatabaseStoreRepository;
use App\Repositories\DatabaseUserRepository;
use App\Repositories\JsonLanguageRepository;
use App\Repositories\JsonTranslationRepository;
use App\Services\AppSettingsService;
use App\Services\Calendar\CalendarEventBuilder;
use App\Services\Calendar\GoogleCalendarClient;
use App\Services\Calendar\GoogleCalendarSettings;
use App\Services\CalendarSyncService;
use App\Services\NotificationService;
use App\Services\PublicUrlResolver;
use App\Services\SecretBox;
use Illuminate\Translation\Translator;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

/**
 * Banc d'essai de la synchronisation d'agenda : vrai schéma (migrations sur SQLite en mémoire), vrais dépôts, transport
 * HTTP factice qui enregistre les appels envoyés à Google. Deux employés (alice, bob), un magasin à Tokyo, un type de shift.
 */
trait CalendarSyncFixture
{
    private Capsule $capsule;
    private FakeHttpTransport $http;
    private InMemoryAppSettings $values;
    private AppSettingsService $appSettings;
    private GoogleCalendarSettings $google;
    private SecretBox $secrets;
    private CalendarSyncService $sync;
    private object $notifications;
    /** @var list<array<string, mixed>> congés fournis par le faux fournisseur d'absences */
    private array $absences = [];
    private int $alice;
    private int $bob;
    private string $day;

    private function setUpCalendarFixture(): void
    {
        $this->capsule = kintai_test_database();

        kintai_test_migrate();

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
        $this->capsule->table('shift_types')->insert(['name' => 'Matin', 'code' => 'M', 'start_time' => '09:00', 'end_time' => '17:00', 'created_at' => $now, 'updated_at' => $now]);
        $this->day = date('Y-m-d', strtotime('+3 days'));

        $translations = kintai_test_real_translator('en', false);

        $this->values  = new InMemoryAppSettings(['app_subtitle' => 'Sakura Co.']);
        $this->secrets = new SecretBox();
        $this->google  = new GoogleCalendarSettings($this->secrets);
        $this->google->save(true, 'client-id', 'client-secret');

        $this->http = new FakeHttpTransport();
        $this->http->when('POST', '/token', ['status' => 200, 'body' => json_encode(['access_token' => 'ACCESS'])]);
        $this->http->when('POST', '/calendars', ['status' => 200, 'body' => json_encode(['id' => 'cal-1'])]);
        $eventCounter = 0;
        $this->http->when('POST', '/events', function () use (&$eventCounter) {
            $eventCounter++;
            return ['status' => 200, 'body' => json_encode(['id' => 'evt-' . $eventCounter])];
        });
        $this->notifications = new class extends NotificationService {
            /** @var list<array{int, string, string}> */
            public array $sent = [];

            public function __construct() {}

            public function notify(int $userId, string $type, string $bodyKey, array $replace = [], ?int $referenceId = null, ?string $link = null): void
            {
                $this->sent[] = [$userId, $type, $bodyKey];
            }
        };
        $this->appSettings = new AppSettingsService();

        $this->sync = new CalendarSyncService(
            new GoogleCalendarClient($this->google, $this->http),
            $this->google,
            $this->secrets,
            new DatabaseStoreRepository(),
            new DatabaseUserRepository(),
            new CalendarEventBuilder($translations),
            $this->appSettings,
            $this->notifications,
            new PublicUrlResolver($this->appSettings, 'https://kintai.test'),
        );
        $this->sync->useAbsenceReader(fn(int $userId): array => array_values(array_filter($this->absences, fn(array $a) => (int) $a['user_id'] === $userId)));
    }

    private function tearDownCalendarFixture(): void
    {
        kintai_test_translator_reset();
    }
}
