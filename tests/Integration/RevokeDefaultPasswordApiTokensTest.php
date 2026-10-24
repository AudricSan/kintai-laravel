<?php

declare(strict_types=1);

namespace kintai\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Database\MigrationRunner;
use PHPUnit\Framework\TestCase;

/**
 * Les jetons API délivrés avec le mot de passe par défaut « 0000 », avant que l'API ne le refuse, sont révoqués
 * par la migration 2026_10_01_000001 ; les autres jetons restent valides.
 */
final class RevokeDefaultPasswordApiTokensTest extends TestCase
{
    private const MIGRATION = '/database/migrations/php/2026_10_01_000001_revoke_api_tokens_of_default_password_accounts.php';

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

    public function testOnlyTokensOfAccountsStillOnTheDefaultPasswordAreRevoked(): void
    {
        $db = $this->capsule->getConnection();
        foreach ([[1, '0000'], [2, bin2hex(random_bytes(6))], [3, '0000']] as [$id, $password]) {
            $db->table('users')->insert([
                'id' => $id, 'email' => "u{$id}@kintai.test", 'first_name' => 'A', 'last_name' => 'B', 'display_name' => 'A B',
                'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]),
            ]);
        }
        foreach ([[1, 'a'], [1, 'b'], [2, 'c']] as [$userId, $token]) {
            $db->table('api_tokens')->insert(['user_id' => $userId, 'token' => str_repeat($token, 64)]);
        }

        $loader = new class($this->capsule) {
            public function __construct(public Capsule $capsule) {}

            public function load(string $file): object
            {
                return require $file;
            }
        };
        $loader->load(dirname(__DIR__, 2) . self::MIGRATION)->up();

        $this->assertSame([2], array_map('intval', $db->table('api_tokens')->pluck('user_id')->all()));
    }
}
