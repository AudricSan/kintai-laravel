<?php

declare(strict_types=1);

namespace kintai\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use kintai\Core\Database\MigrationRunner;
use PHPUnit\Framework\TestCase;

/**
 * La migration 2026_10_15_000001 donne une échéance de 90 jours aux jetons API qui n'en avaient pas, sans
 * toucher à ceux qui en ont déjà une.
 */
final class ExpireApiTokensWithoutExpiryTest extends TestCase
{
    private const MIGRATION = '/database/migrations/php/2026_10_15_000001_expire_api_tokens_without_expiry.php';

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

        // La colonne manquait sur une installation neuve avant la migration 2026_10_13_000000 (autre correctif).
        $schema = $this->capsule->schema();
        if (!$schema->hasColumn('api_tokens', 'expires_at')) {
            $schema->table('api_tokens', fn(Blueprint $t) => $t->dateTime('expires_at')->nullable());
        }
    }

    public function testTokensWithoutExpiryGetNinetyDaysAndOthersAreKept(): void
    {
        $db = $this->capsule->getConnection();
        $db->table('users')->insert(['id' => 1, 'email' => 'u@kintai.test', 'first_name' => 'A', 'last_name' => 'B', 'display_name' => 'A B', 'password_hash' => 'x']);
        $db->table('api_tokens')->insert(['id' => 1, 'user_id' => 1, 'token' => str_repeat('a', 64), 'expires_at' => null]);
        $db->table('api_tokens')->insert(['id' => 2, 'user_id' => 1, 'token' => str_repeat('b', 64), 'expires_at' => '2030-01-01 00:00:00']);

        $loader = new class($this->capsule) {
            public function __construct(public Capsule $capsule) {}

            public function load(string $file): object
            {
                return require $file;
            }
        };
        $loader->load(dirname(__DIR__, 2) . self::MIGRATION)->up();

        $expiries = $db->table('api_tokens')->orderBy('id')->pluck('expires_at', 'id')->all();
        $this->assertEqualsWithDelta(time() + 90 * 86400, strtotime((string) $expiries[1]), 5);
        $this->assertSame('2030-01-01 00:00:00', $expiries[2]);
    }
}
