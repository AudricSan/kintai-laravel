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
    private const MIGRATION = '/database/migrations/php/2026_10_16_000001_repair_orphans_for_sqlite_foreign_keys.php';

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

    private function runRepair(): void
    {
        $loader = new class($this->capsule) {
            public function __construct(public Capsule $capsule) {}

            public function load(string $file): object
            {
                return require $file;
            }
        };
        $loader->load(dirname(__DIR__, 2) . self::MIGRATION)->up();
    }

    private function seedOrphans(): void
    {
        $db = $this->capsule->getConnection();
        $db->table('users')->insert(['id' => 1, 'email' => 'a@kintai.test', 'first_name' => 'A', 'last_name' => 'B', 'display_name' => 'A B', 'password_hash' => 'x']);
        $db->table('stores')->insert(['id' => 1, 'name' => 'Tokyo', 'code' => 'TKY']);

        // Shift d'un compte (50) et d'un magasin (60) effacés avant le correctif : historique à garder.
        $db->table('shifts')->insert([
            'id' => 10, 'store_id' => 60, 'user_id' => 50, 'shift_date' => '2026-05-01', 'start_time' => '09:00', 'end_time' => '17:00',
            'starts_at' => '2026-05-01 09:00:00', 'ends_at' => '2026-05-01 17:00:00', 'duration_minutes' => 480,
        ]);
        // Candidature liée à un shift disparu (999) : ON DELETE CASCADE → supprimée.
        $db->table('shift_claims')->insert(['id' => 5, 'shift_id' => 999, 'user_id' => 1, 'store_id' => 1]);
        // Congé traité par un compte disparu (70) : le compte est recréé, la trace de qui a traité est gardée.
        $db->table('timeoff_requests')->insert(['id' => 3, 'user_id' => 1, 'store_id' => 1, 'start_date' => '2026-05-02', 'end_date' => '2026-05-03', 'processed_by' => 70]);
    }

    public function testMissingAccountsAndStoresAreRestoredAsDeletedGhostsAndHistoryIsKept(): void
    {
        $this->seedOrphans();
        $this->runRepair();
        $db = $this->capsule->getConnection();

        $this->assertSame(1, $db->table('shifts')->where('id', 10)->count());

        $ghost = $db->table('users')->where('id', 50)->first();
        $this->assertSame('deleted-50@kintai.local', $ghost->email);
        $this->assertSame(0, (int) $ghost->is_active);
        $this->assertNotNull($ghost->deleted_at);
        $this->assertNotNull($db->table('users')->where('id', 70)->value('deleted_at'));

        $store = $db->table('stores')->where('id', 60)->first();
        $this->assertSame('DEL-60', $store->code);
        $this->assertNotNull($store->deleted_at);

        $this->assertSame(0, $db->table('shift_claims')->where('id', 5)->count());
        $this->assertSame(70, (int) $db->table('timeoff_requests')->where('id', 3)->value('processed_by'));
    }

    public function testNoForeignKeyViolationRemainsOnceConstraintsAreEnabled(): void
    {
        $this->seedOrphans();
        $this->runRepair();
        $db = $this->capsule->getConnection();

        $db->statement('PRAGMA foreign_keys = ON');
        $this->assertSame([], $db->select('PRAGMA foreign_key_check'));
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
