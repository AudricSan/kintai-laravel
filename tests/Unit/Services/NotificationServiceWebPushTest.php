<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use App\BundleManager;
use App\Extension\ExtensionRegistry;
use App\Repositories\AppSettingsRepositoryInterface;
use App\Repositories\DatabaseNotificationRepository;
use App\Repositories\DevicePushTokenRepositoryInterface;
use App\Repositories\JsonLanguageRepository;
use App\Repositories\JsonTranslationRepository;
use App\Repositories\UserRepositoryInterface;
use App\Repositories\WebPushSubscriptionRepositoryInterface;
use App\Services\NotificationPreferenceService;
use App\Services\NotificationService;
use App\Services\NotificationTypeRegistry;
use App\Services\PushNotificationService;
use Illuminate\Translation\Translator;
use App\Services\VapidKeyStore;
use App\Services\WebPushService;
use App\Models\Notification;
use PHPUnit\Framework\TestCase;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 3));
}

/** NotificationService envoie aussi le web push natif, sous les mêmes conditions que le push mobile. */
final class NotificationServiceWebPushTest extends TestCase
{
    /** @var list<array> chargements d'abonnements demandés (un par utilisateur ayant le droit d'être poussé) */
    private array $lookups = [];
    private NotificationPreferenceService $prefs;
    private NotificationService $service;

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $schema = $capsule->getConnection()->getSchemaBuilder();
        $schema->create('notifications', function ($table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('type');
            $table->string('body_key')->nullable();
            $table->text('params')->nullable();
            $table->text('body')->nullable();
            $table->string('link', 500)->nullable();
            $table->string('reference_type', 40)->nullable();
            $table->integer('reference_id')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
        $schema->create('notification_preferences', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->string('type', 64);
            $table->string('channel', 16);
            $table->boolean('enabled');
            $table->unique(['user_id', 'type', 'channel']);
        });

        $users = $this->createStub(UserRepositoryInterface::class);
        $stored = [];
        $users->method('findById')->willReturnCallback(function (int $id) use (&$stored) {
            return $stored[$id] ??= ['id' => $id, 'language' => 'fr', 'quiet_hours_start' => null, 'quiet_hours_end' => null];
        });
        $users->method('save')->willReturnCallback(function (array $data) use (&$stored) {
            $stored[(int) $data['id']] = $data;
            return $data;
        });

        $manager  = (new \ReflectionClass(BundleManager::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($manager, 'bundles'))->setValue($manager, []);
        $registry = new NotificationTypeRegistry(new ExtensionRegistry($manager));
        $this->prefs = new NotificationPreferenceService($registry, $users);

        $settings = new class implements AppSettingsRepositoryInterface {
            public array $values = [];

            public function all(): array { return $this->values; }
            public function get(string $key): ?string { return $this->values[$key] ?? null; }
            public function set(string $key, string $value): void { $this->values[$key] = $value; }
            public function setMany(array $settings): void { $this->values = $settings + $this->values; }
        };
        $keys = new VapidKeyStore($settings);
        $keys->ensure();

        $lookups = &$this->lookups;
        $subscriptions = new class($lookups) implements WebPushSubscriptionRepositoryInterface {
            public function __construct(private array &$lookups) {}

            public function findByUser(int $userId): array { return []; }
            public function findByUsers(array $userIds): array { $this->lookups[] = $userIds; return []; }
            public function findByEndpoint(string $endpoint): ?array { return null; }
            public function save(int $userId, string $endpoint, string $p256dh, string $auth, ?string $userAgent): array { return []; }
            public function deleteByEndpoint(string $endpoint, ?int $userId = null): int { return 0; }
            public function deleteById(int $id): void {}
            public function touchLastUsed(int $id): void {}
            public function deleteAllForUser(int $userId): void {}
            public function countAll(): int { return 0; }
            public function purgeUnusedSince(string $before): int { return 0; }
        };

        $this->service = new NotificationService(
            new DatabaseNotificationRepository(),
            new PushNotificationService([], $this->createStub(DevicePushTokenRepositoryInterface::class)),
            kintai_test_real_translator(),
            $users,
            $registry,
            $this->prefs,
            new WebPushService($subscriptions, $keys),
        );
    }

    public function testNotifyAsksForTheRecipientsBrowsers(): void
    {
        $this->service->notify(7, 'shift_assigned', 'notif_shift_assigned_body');

        $this->assertSame([[7]], $this->lookups);
        $this->assertSame(1, Notification::count());
    }

    public function testNoWebPushWhenTheUserTurnedPushOffButTheBellStillGetsIt(): void
    {
        $this->prefs->saveChanges(7, ['shift_assigned' => ['push' => false]]);

        $this->service->notify(7, 'shift_assigned', 'notif_shift_assigned_body');

        $this->assertSame([], $this->lookups);
        $this->assertSame(1, Notification::count());
    }

    /** Rien n'est retenu : pendant les heures silencieuses le web push part aussi (affiché sans son, voir pushPayload). */
    public function testWebPushIsStillAttemptedDuringQuietHours(): void
    {
        // Plage couvrant toute la journée sauf une minute : « maintenant » y tombe presque sûrement, on la construit autour de l'heure courante.
        $now   = new \DateTimeImmutable();
        $start = $now->modify('-1 hour')->format('H:i');
        $end   = $now->modify('+1 hour')->format('H:i');
        $this->assertTrue($this->prefs->saveQuietHours(7, true, $start, $end));
        $this->assertTrue($this->prefs->isQuietNow(7), 'précondition');

        $this->service->notify(7, 'shift_assigned', 'notif_shift_assigned_body');

        $this->assertSame([[7]], $this->lookups, 'les abonnements sont lus : le push n\'est pas retenu');
        $this->assertSame(1, Notification::count());
    }

    public function testNotifyManyLoadsTheSubscriptionsOfAllRecipientsInOneQuery(): void
    {
        $this->service->notifyMany([7, 8, 9], 'shift_assigned', 'notif_shift_assigned_body');

        $this->assertSame([[7, 8, 9]], $this->lookups, 'une seule requête pour tous les destinataires');
        $this->assertSame(3, Notification::count());
    }
}
