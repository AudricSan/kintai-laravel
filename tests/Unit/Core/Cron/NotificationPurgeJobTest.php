<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Cron;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Cron\CronRunner;
use kintai\Core\Cron\NotificationPurgeJob;
use kintai\Core\Repositories\DatabaseNotificationRepository;
use kintai\Core\Repositories\DatabaseWebPushSubscriptionRepository;
use kintai\Core\Request;
use kintai\Core\Services\AppSettingsService;
use kintai\Domain\Eloquent\Notification;
use kintai\Domain\Eloquent\WebPushSubscription;
use kintai\Tests\Support\InMemoryAppSettings;
use PHPUnit\Framework\TestCase;

/** Purge des notifications lues (jamais des non lues) et des abonnements web push inutilisés, contre un vrai schéma. */
final class NotificationPurgeJobTest extends TestCase
{
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
        $schema->create('web_push_subscriptions', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->index();
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();
            $table->string('p256dh', 255);
            $table->string('auth', 255);
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('last_used_at')->nullable();
        });
    }

    private function job(array $settings = []): NotificationPurgeJob
    {
        return new NotificationPurgeJob(
            new DatabaseNotificationRepository(),
            new DatabaseWebPushSubscriptionRepository(),
            new AppSettingsService(new InMemoryAppSettings($settings)),
        );
    }

    private function daysAgo(int $days): string
    {
        return date('Y-m-d H:i:s', strtotime("-{$days} days"));
    }

    private function notification(int $userId, ?int $readDaysAgo, int $createdDaysAgo = 400): int
    {
        return (int) Notification::create([
            'user_id'    => $userId,
            'type'       => 'shift_assigned',
            'body'       => 'x',
            'read_at'    => $readDaysAgo === null ? null : $this->daysAgo($readDaysAgo),
            'created_at' => $this->daysAgo($createdDaysAgo),
        ])->id;
    }

    private function subscription(string $endpoint, ?int $lastUsedDaysAgo, int $createdDaysAgo): int
    {
        return (int) WebPushSubscription::create([
            'user_id' => 1, 'endpoint' => $endpoint, 'endpoint_hash' => hash('sha256', $endpoint), 'p256dh' => 'k', 'auth' => 'a',
            'created_at'   => $this->daysAgo($createdDaysAgo),
            'last_used_at' => $lastUsedDaysAgo === null ? null : $this->daysAgo($lastUsedDaysAgo),
        ])->id;
    }

    public function testNameIsReservedForTheCore(): void
    {
        $this->assertSame('notification-purge', $this->job()->getName());
        $this->assertContains('notification-purge', CronRunner::CORE_JOBS);
    }

    public function testDeletesOnlyReadNotificationsOlderThanTheRetention(): void
    {
        $oldRead    = $this->notification(1, 100);
        $recentRead = $this->notification(1, 10);
        $oldUnread  = $this->notification(1, null, 400);

        $response = json_decode($this->job()->run(new Request())->body(), true);

        $this->assertTrue($response['ok']);
        $this->assertSame(90, $response['retention_days']);
        $this->assertSame(1, $response['notifications_deleted']);
        $this->assertNull(Notification::find($oldRead));
        $this->assertNotNull(Notification::find($recentRead), 'lue depuis moins de 90 jours');
        $this->assertNotNull(Notification::find($oldUnread), 'une notification non lue n\'est jamais supprimée, quel que soit son âge');
    }

    public function testTheRetentionComesFromTheOwnersSettingWithAMinimumOfSevenDays(): void
    {
        $this->notification(1, 20);
        $this->notification(1, 5);

        $this->job(['notifications_retention_days' => '14'])->run(new Request());
        $this->assertSame(1, Notification::count(), 'lue depuis 20 jours > 14 : supprimée ; 5 jours : conservée');

        $this->notification(1, 8);
        $this->job(['notifications_retention_days' => '1'])->run(new Request());
        $this->assertSame(1, Notification::count(), 'une valeur sous le minimum est ramenée à 7 jours : celle de 8 jours est supprimée, celle de 5 jours reste');
    }

    public function testDeletesInBatchesUntilEverythingOldIsGone(): void
    {
        $repo = new DatabaseNotificationRepository();
        for ($i = 0; $i < 25; $i++) {
            $this->notification(1, 200);
        }
        $keep = $this->notification(1, 1);

        $deleted = $repo->purgeReadBefore($this->daysAgo(90), 10);

        $this->assertSame(25, $deleted);
        $this->assertSame(1, Notification::count());
        $this->assertNotNull(Notification::find($keep));
    }

    public function testPurgeWithNothingToDoReturnsZero(): void
    {
        $this->assertSame(0, (new DatabaseNotificationRepository())->purgeReadBefore($this->daysAgo(90)));
    }

    public function testDeletesWebPushSubscriptionsUnusedForMoreThan180Days(): void
    {
        $dead        = $this->subscription('https://push.example/dead', 200, 400);
        $neverUsedOld = $this->subscription('https://push.example/never-old', null, 250);
        $neverUsedNew = $this->subscription('https://push.example/never-new', null, 5);
        $active      = $this->subscription('https://push.example/active', 3, 400);

        $response = json_decode($this->job()->run(new Request())->body(), true);

        $this->assertSame(2, $response['subscriptions_deleted']);
        $this->assertNull(WebPushSubscription::find($dead));
        $this->assertNull(WebPushSubscription::find($neverUsedOld), 'jamais utilisé et plus vieux que le délai');
        $this->assertNotNull(WebPushSubscription::find($neverUsedNew));
        $this->assertNotNull(WebPushSubscription::find($active), 'ancien mais utilisé récemment');
    }

    public function testCountAll(): void
    {
        $this->subscription('https://push.example/a', 1, 1);
        $this->subscription('https://push.example/b', 1, 1);

        $this->assertSame(2, (new DatabaseWebPushSubscriptionRepository())->countAll());
    }
}
