<?php

declare(strict_types=1);

namespace kintai\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Database\Migration;
use kintai\Core\Database\MigrationRunner;
use PHPUnit\Framework\TestCase;

/**
 * settings.update, shifts.export et shifts.validate étaient cochables dans
 * l'écran de rôle sans qu'aucune route/contrôleur ne les vérifie jamais.
 * Retirées de PermissionCatalog ; la migration
 * 2026_09_09_000002_remove_dead_permission_keys nettoie les lignes
 * role_permissions correspondantes.
 */
final class DeadPermissionKeysCleanupTest extends TestCase
{
    private function migratedCapsule(): Capsule
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $runner = (new \ReflectionClass(MigrationRunner::class))->newInstanceWithoutConstructor();
        $capsuleProp = new \ReflectionProperty($runner, 'capsule');
        $capsuleProp->setAccessible(true);
        $capsuleProp->setValue($runner, $capsule);
        $pathProp = new \ReflectionProperty($runner, 'migrationsPath');
        $pathProp->setAccessible(true);
        $pathProp->setValue($runner, dirname(__DIR__, 2) . '/database/migrations/php');

        $runner->run();

        return $capsule;
    }

    private function reRunCleanupMigration(Capsule $capsule): void
    {
        $file = dirname(__DIR__, 2) . '/database/migrations/php/2026_09_09_000002_remove_dead_permission_keys.php';
        $loader = new class($capsule) {
            public Capsule $capsule;
            public function __construct(Capsule $capsule) { $this->capsule = $capsule; }
            public function load(string $file): Migration
            {
                return require $file;
            }
        };
        $loader->load($file)->up();
    }

    private function createCustomRole(Capsule $capsule, string $slug): int
    {
        $conn = $capsule->getConnection();
        $conn->table('roles')->insert([
            'name' => $slug, 'slug' => $slug, 'is_system' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return (int) $conn->table('roles')->where('slug', $slug)->value('id');
    }

    public function testRemovesTheThreeDeadKeysButKeepsLiveOnes(): void
    {
        $capsule = $this->migratedCapsule();
        $conn    = $capsule->getConnection();

        $roleId = $this->createCustomRole($capsule, 'dead-keys-test');
        $conn->table('role_permissions')->insert([
            ['role_id' => $roleId, 'permission_key' => 'settings.update'],
            ['role_id' => $roleId, 'permission_key' => 'shifts.export'],
            ['role_id' => $roleId, 'permission_key' => 'shifts.validate'],
            ['role_id' => $roleId, 'permission_key' => 'shifts.view'],
        ]);

        $this->reRunCleanupMigration($capsule);

        $granted = $conn->table('role_permissions')->where('role_id', $roleId)->pluck('permission_key')->all();

        $this->assertSame(['shifts.view'], $granted);
    }

    public function testFullMigrationRunLeavesNoDeadKeyBehind(): void
    {
        // shifts.import/view/create/update/delete sont accordées au Manager
        // seedé (2026_07_14_000005) mais plus shifts.export/validate depuis
        // que PermissionCatalog ne les déclare plus.
        $capsule = $this->migratedCapsule();

        $remaining = $capsule->getConnection()
            ->table('role_permissions')
            ->whereIn('permission_key', ['settings.update', 'shifts.export', 'shifts.validate'])
            ->count();

        $this->assertSame(0, $remaining);
    }
}
