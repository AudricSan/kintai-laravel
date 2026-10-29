<?php

declare(strict_types=1);

namespace kintai\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Database\MigrationRunner;
use kintai\Core\Database\SqliteForeignKeys;
use PHPUnit\Framework\TestCase;

/**
 * Audit du 13/10/2026 (M8) : clés étrangères SQLite activées, après réparation des orphelins existants
 * (migration 2026_10_16_000001) — comptes et magasins disparus recréés comme parents fantômes pour garder
 * l'historique, autres orphelins traités selon la règle de leur clé.
 */
final class SqliteForeignKeysTest extends TestCase
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
            $p->setValue($runner, $value);
        }
        $runner->run();
    }





    /** Une fois actives, les règles onDelete s'appliquent comme sous MySQL. */
    public function testEnabledConstraintsCascadeLikeMysql(): void
    {
        $db = $this->capsule->getConnection();
        $db->statement('PRAGMA foreign_keys = ON');
        $db->table('users')->insert(['id' => 1, 'email' => 'a@kintai.test', 'first_name' => 'A', 'last_name' => 'B', 'display_name' => 'A B', 'password_hash' => 'x']);
        $db->table('api_tokens')->insert(['user_id' => 1, 'token' => str_repeat('a', 64)]);

        $db->table('users')->where('id', 1)->delete();

        $this->assertSame(0, $db->table('api_tokens')->count());

        $this->expectException(\Illuminate\Database\QueryException::class);
        $db->table('api_tokens')->insert(['user_id' => 404, 'token' => str_repeat('b', 64)]);
    }

    public function testWithoutSuspendsThenRestoresTheConstraints(): void
    {
        $db = $this->capsule->getConnection();
        $db->statement('PRAGMA foreign_keys = ON');

        $inside = SqliteForeignKeys::without($db, fn() => SqliteForeignKeys::enabled($db));

        $this->assertFalse($inside);
        $this->assertTrue(SqliteForeignKeys::enabled($db));

        $db->statement('PRAGMA foreign_keys = OFF');
        SqliteForeignKeys::without($db, fn() => null);
        $this->assertFalse(SqliteForeignKeys::enabled($db), "l'état d'origine (désactivé) est conservé");
    }

    /** Toutes les migrations passent sur une base dont les clés sont actives (cas d'une mise à jour). */
    public function testMigrationsRunWithConstraintsEnabled(): void
    {
        $fresh = new Capsule();
        $fresh->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        // Les migrations passent par la capsule globale (Capsule::table/schema statiques).
        $fresh->setAsGlobal();
        $runner = (new \ReflectionClass(MigrationRunner::class))->newInstanceWithoutConstructor();
        foreach (['capsule' => $fresh, 'migrationsPath' => dirname(__DIR__, 2) . '/database/migrations/php'] as $prop => $value) {
            $p = new \ReflectionProperty($runner, $prop);
            $p->setValue($runner, $value);
        }

        $this->assertTrue(SqliteForeignKeys::enabled($fresh->getConnection()));
        $this->assertGreaterThan(0, $runner->run());
        $this->assertTrue(SqliteForeignKeys::enabled($fresh->getConnection()));
        $this->assertSame([], $fresh->getConnection()->select('PRAGMA foreign_key_check'));
    }
}
