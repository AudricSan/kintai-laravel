<?php

declare(strict_types=1);

namespace kintai\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Database\MigrationRunner;
use PHPUnit\Framework\TestCase;

/** Migration du web push natif : table des abonnements, clés VAPID créées, une seule fois. */
final class WebPushMigrationTest extends TestCase
{
    private Capsule $capsule;

    protected function setUp(): void
    {
        $this->capsule = new Capsule();
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();
    }

    private function migrate(): void
    {
        $runner = (new \ReflectionClass(MigrationRunner::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($runner, 'capsule'))->setValue($runner, $this->capsule);
        (new \ReflectionProperty($runner, 'migrationsPath'))->setValue($runner, dirname(__DIR__, 2) . '/database/migrations/php');
        $runner->run();
    }

    public function testFreshInstanceGetsTheTableAndTheVapidKeys(): void
    {
        $this->migrate();
        $conn = $this->capsule->getConnection();

        $this->assertTrue($conn->getSchemaBuilder()->hasTable('web_push_subscriptions'));
        $this->assertTrue($conn->getSchemaBuilder()->hasColumns('web_push_subscriptions', ['user_id', 'endpoint', 'endpoint_hash', 'p256dh', 'auth', 'user_agent', 'created_at', 'last_used_at']));
        $public  = $conn->table('app_settings')->where('key', 'web_push_vapid_public')->value('value');
        $private = $conn->table('app_settings')->where('key', 'web_push_vapid_private')->value('value');
        $this->assertNotEmpty($public);
        $this->assertNotEmpty($private);
        $this->assertNotSame($public, $private);
    }

    public function testRunningTwiceKeepsTheSameKeys(): void
    {
        $this->migrate();
        $before = $this->capsule->getConnection()->table('app_settings')->where('key', 'web_push_vapid_private')->value('value');

        $this->migrate();

        $this->assertSame($before, $this->capsule->getConnection()->table('app_settings')->where('key', 'web_push_vapid_private')->value('value'));
    }

    public function testEndpointHashIsUniqueAndLongEndpointsFit(): void
    {
        $this->migrate();
        $conn = $this->capsule->getConnection();
        $row = ['user_id' => 1, 'endpoint' => 'https://push.example/' . str_repeat('x', 1500), 'endpoint_hash' => hash('sha256', 'a'), 'p256dh' => 'k', 'auth' => 'a'];
        $conn->table('web_push_subscriptions')->insert($row);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $conn->table('web_push_subscriptions')->insert($row);
    }
}
