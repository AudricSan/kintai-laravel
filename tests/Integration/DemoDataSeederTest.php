<?php

declare(strict_types=1);

namespace kintai\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Auth\PasswordPolicy;
use kintai\Core\Database\DemoDataSeeder;
use kintai\Core\Database\MigrationRunner;
use PHPUnit\Framework\TestCase;

/**
 * Les anciens seeds SQL étaient figés sur le schéma des premières versions (la colonne stores.break_settings,
 * supprimée depuis, les faisait échouer). Le seeder passe par les modèles : ce test le rejoue sur un schéma
 * migré, deux fois de suite (idempotence).
 */
final class DemoDataSeederTest extends TestCase
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

    public function testSeedsAConsistentDemoOnTheCurrentSchema(): void
    {
        $result = (new DemoDataSeeder())->run();
        $db = $this->capsule->getConnection();

        $this->assertSame(2, $result['stores']);
        $this->assertSame(3, $result['shift_types']);
        $this->assertSame(6, $result['users']);
        $this->assertGreaterThan(0, $result['shifts']);
        $this->assertSame(2, $db->table('stores')->count());
        $this->assertSame(6, $db->table('users')->count());
        $this->assertSame(2, $db->table('role_assignments')->join('roles', 'roles.id', '=', 'role_assignments.role_id')->where('roles.slug', 'manager')->count());
        // Chaque type de shift est activé dans les deux magasins ; l'employé partagé est membre des deux.
        $this->assertSame(6, $db->table('shift_type_stores')->count());
        $this->assertSame(2, $db->table('store_user')->where('user_id', $db->table('users')->where('email', 'hugo.moreau@kintai.local')->value('id'))->count());
        // Aucun shift sans heure de début/fin résolue ni sans durée (les statistiques en dépendent).
        $this->assertSame(0, $db->table('shifts')->whereNull('duration_minutes')->count());
    }

    public function testSeedingTwiceCreatesNothingNew(): void
    {
        (new DemoDataSeeder())->run();
        $again = (new DemoDataSeeder())->run();

        $this->assertSame(0, $again['users']);
        $this->assertSame(0, $again['shifts']);
        $this->assertSame(6, $this->capsule->getConnection()->table('users')->count());
    }

    public function testDemoPasswordIsLongEnoughForTheApi(): void
    {
        $this->assertTrue(PasswordPolicy::isLongEnough(DemoDataSeeder::PASSWORD));
    }
}
