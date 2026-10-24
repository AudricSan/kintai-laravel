<?php

declare(strict_types=1);

namespace kintai\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Database\MigrationRunner;
use PHPUnit\Framework\TestCase;

/**
 * Une installation neuve doit pouvoir créer un jeton d'API : la colonne expires_at, écrite par la connexion API
 * et lue par ApiAuthMiddleware, n'existait dans aucune migration (500 sur POST /api/v1/auth/login).
 */
final class ApiTokensExpiresAtTest extends TestCase
{
    private Capsule $capsule;

    protected function setUp(): void
    {
        $this->capsule = new Capsule();
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $runner = (new \ReflectionClass(MigrationRunner::class))->newInstanceWithoutConstructor();
        foreach (['capsule' => $this->capsule, 'migrationsPath' => dirname(__DIR__, 2) . '/database/migrations/php'] as $prop => $value) {
            $p = new \ReflectionProperty($runner, $prop);
            $p->setAccessible(true);
            $p->setValue($runner, $value);
        }
        $runner->run();
    }

    public function testFreshSchemaHasTheColumnsTheApiLoginWrites(): void
    {
        $schema = $this->capsule->getConnection()->getSchemaBuilder();
        foreach (['user_id', 'token', 'name', 'expires_at', 'last_used_at', 'created_at'] as $column) {
            $this->assertTrue($schema->hasColumn('api_tokens', $column), "api_tokens.$column manquante");
        }
    }

    public function testATokenWithAnExpiryCanBeInserted(): void
    {
        $db = $this->capsule->getConnection();
        $db->table('users')->insert([
            'id' => 1, 'email' => 'a@b.test', 'password_hash' => 'x', 'first_name' => 'A', 'last_name' => 'B', 'display_name' => 'A B',
        ]);
        $db->table('api_tokens')->insert([
            'user_id' => 1, 'token' => str_repeat('a', 64), 'name' => 't', 'expires_at' => '2030-01-01 00:00:00',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertSame('2030-01-01 00:00:00', $db->table('api_tokens')->value('expires_at'));
    }
}
