<?php

declare(strict_types=1);

namespace kintai\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Database\MigrationRunner;
use kintai\Core\Repositories\DatabaseRouteSlugRepository;
use kintai\Core\Repositories\DatabaseStoreRepository;
use kintai\Core\Repositories\DatabaseUserRepository;
use kintai\Core\Routing\EmployeeRouteBinder;
use kintai\Core\Routing\StoreRouteBinder;
use PHPUnit\Framework\TestCase;

/**
 * Alias d'URL sur une vraie base SQLite : remplissage par la migration (noms japonais tels quels), historique
 * des numéros d'employé quel que soit le chemin d'écriture, et nettoyage explicite à la suppression (SQLite
 * n'applique pas les cascades ici).
 */
final class RouteSlugsTest extends TestCase
{
    private const MIGRATION = '/database/migrations/php/2026_10_01_000000_create_route_slugs_table.php';

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

    /** Rejoue la migration (idempotente) après avoir inséré des magasins, comme sur une instance existante. */
    private function rerunMigration(): void
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

    private function insertUser(int $id, ?string $code): void
    {
        $this->capsule->getConnection()->table('users')->insert([
            'id' => $id, 'email' => "u{$id}@kintai.test", 'password_hash' => 'x',
            'first_name' => 'A', 'last_name' => 'B', 'display_name' => 'A B', 'employee_code' => $code,
        ]);
    }

    public function testMigrationGivesExistingStoresTheirNameAsAlias(): void
    {
        $this->capsule->getConnection()->table('stores')->insert([
            ['id' => 1, 'name' => '所沢東町店', 'code' => 'A'],
            ['id' => 2, 'name' => 'Shibuya Nord', 'code' => 'B'],
            ['id' => 3, 'name' => 'Shibuya Nord', 'code' => 'C'],
            ['id' => 4, 'name' => 'Create', 'code' => 'D'],
        ]);

        $this->rerunMigration();
        $this->rerunMigration(); // idempotente

        $this->assertSame(
            [1 => '所沢東町店', 2 => 'shibuya-nord', 3 => 'shibuya-nord-2', 4 => 'create-2'],
            (new DatabaseRouteSlugRepository())->currentSlugs('store'),
        );
    }

    public function testStoreBinderResolvesTheJapaneseAliasFromTheDatabase(): void
    {
        $this->capsule->getConnection()->table('stores')->insert(['id' => 1, 'name' => '所沢東町店', 'code' => 'A']);
        $this->rerunMigration();

        $binder = new StoreRouteBinder(new DatabaseRouteSlugRepository(), new DatabaseStoreRepository());

        $this->assertSame(1, $binder->resolve('所沢東町店')?->id);
        $this->assertFalse($binder->resolve('1')->canonical);
    }

    public function testEmployeeNumberChangesAreKeptForRedirects(): void
    {
        $users = new DatabaseUserRepository();
        $this->insertUser(18, '015');

        $users->save(['id' => 18, 'employee_code' => '016']);

        $binder = new EmployeeRouteBinder(new DatabaseRouteSlugRepository());
        $this->assertSame('016', $binder->segmentFor(18));
        $old = $binder->resolve('015');
        $this->assertSame(18, $old?->id);
        $this->assertFalse($old->canonical);
    }

    public function testAReassignedNumberStopsPointingToItsFormerHolder(): void
    {
        $users = new DatabaseUserRepository();
        $this->insertUser(18, '015');
        $this->insertUser(19, '099');

        $users->save(['id' => 18, 'employee_code' => '016']); // « 015 » passe en historique de 18
        $users->save(['id' => 19, 'employee_code' => '015']); // puis est réattribué à 19

        $bound = (new EmployeeRouteBinder(new DatabaseRouteSlugRepository()))->resolve('015');
        $this->assertSame(19, $bound?->id);
        $this->assertTrue($bound->canonical);
    }

    public function testDeletingAStoreOrAUserRemovesTheirAliases(): void
    {
        $db = $this->capsule->getConnection();
        $db->table('stores')->insert(['id' => 1, 'name' => '所沢東町店', 'code' => 'A']);
        $this->rerunMigration();
        $this->insertUser(18, '015');
        (new DatabaseUserRepository())->save(['id' => 18, 'employee_code' => '016']);

        (new DatabaseStoreRepository())->delete(1);
        (new DatabaseUserRepository())->delete(18);

        $this->assertSame(0, $db->table('route_slugs')->count());
    }
}
