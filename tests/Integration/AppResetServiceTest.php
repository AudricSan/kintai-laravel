<?php

declare(strict_types=1);

namespace kintai\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Database\MigrationRunner;
use kintai\Core\Services\AppResetService;
use PHPUnit\Framework\TestCase;

final class AppResetServiceTest extends TestCase
{
    private string $tmpDir;
    private Capsule $capsule;
    private MigrationRunner $runner;
    private AppResetService $service;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/kintai_reset_test_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir . '/storage/uploads/avatars', 0775, true);
        file_put_contents($this->tmpDir . '/storage/uploads/avatars/1.png', 'x');
        file_put_contents($this->tmpDir . '/storage/installed.lock', bin2hex(random_bytes(8)));
        putenv('KINTAI_STORAGE_PATH=' . $this->tmpDir . '/storage');

        $this->capsule = new Capsule();
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $this->runner = (new \ReflectionClass(MigrationRunner::class))->newInstanceWithoutConstructor();
        $capsuleProp = new \ReflectionProperty($this->runner, 'capsule');
        $capsuleProp->setAccessible(true);
        $capsuleProp->setValue($this->runner, $this->capsule);
        $pathProp = new \ReflectionProperty($this->runner, 'migrationsPath');
        $pathProp->setAccessible(true);
        $pathProp->setValue($this->runner, dirname(__DIR__, 2) . '/database/migrations/php');
        $this->runner->run();

        $this->seedMinimalData();

        $this->service = new AppResetService($this->capsule, $this->runner);
    }

    protected function tearDown(): void
    {
        putenv('KINTAI_STORAGE_PATH');
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->tmpDir);
    }

    private function seedMinimalData(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->capsule->table('users')->insert([
            'first_name' => 'Owner', 'last_name' => 'Test', 'display_name' => 'Owner Test',
            'email' => 'owner@test.com', 'password_hash' => 'x', 'is_active' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->capsule->table('stores')->insert([
            'name' => 'Store A', 'code' => 'STA', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->capsule->table('shift_types')->insert([
            'name' => 'Matin', 'code' => 'M',
            'start_time' => '09:00', 'end_time' => '17:00',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->capsule->table('shift_type_stores')->insert(['shift_type_id' => 1, 'store_id' => 1]);
        $this->capsule->table('shifts')->insert([
            'user_id' => 1, 'store_id' => 1, 'shift_type_id' => 1,
            'shift_date' => '2026-07-15', 'start_time' => '09:00', 'end_time' => '17:00',
            'starts_at' => '2026-07-15 09:00:00', 'ends_at' => '2026-07-15 17:00:00',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->capsule->table('app_settings')->insert(['key' => 'update_channel', 'value' => 'beta']);
    }

    public function testResetDataWipesOperationalTablesButKeepsIdentityAndConfig(): void
    {
        $this->service->resetData([]);

        $this->assertSame(1, $this->capsule->table('users')->count(), 'users doit être conservé');
        // Les clés VAPID (créées par la migration) restent avec le réglage posé par le test : rien n'est supprimé.
        $this->assertSame('beta', $this->capsule->table('app_settings')->where('key', 'update_channel')->value('value'), 'app_settings doit être conservé');
        $this->assertNotEmpty($this->capsule->table('app_settings')->where('key', 'web_push_vapid_private')->value('value'), 'les clés VAPID doivent être conservées');
        $this->assertSame(0, $this->capsule->table('shifts')->count(), 'shifts doit être vidé');
        $this->assertSame(0, $this->capsule->table('stores')->count(), 'stores doit être vidé par défaut (non coché)');
        $this->assertSame(0, $this->capsule->table('shift_types')->count(), 'shift_types doit être vidé par défaut (non coché)');
        $this->assertSame(0, $this->capsule->table('shift_type_stores')->count(), 'shift_type_stores doit être vidé par défaut (non coché)');
    }

    public function testResetDataKeepsOptedInCategories(): void
    {
        $this->service->resetData(['stores', 'shift_types']);

        $this->assertSame(1, $this->capsule->table('stores')->count(), 'stores doit être conservé si coché');
        $this->assertSame(1, $this->capsule->table('shift_types')->count(), 'shift_types doit être conservé si coché');
        $this->assertSame(1, $this->capsule->table('shift_type_stores')->count(), 'shift_type_stores (pivot) doit être conservé avec shift_types');
        $this->assertSame(0, $this->capsule->table('shifts')->count(), 'shifts reste vidé (donnée opérationnelle, pas une catégorie optionnelle)');
    }

    public function testResetDataDoesNotTouchInstalledLockOrUploads(): void
    {
        $this->service->resetData([]);

        $this->assertFileExists($this->tmpDir . '/storage/installed.lock');
        $this->assertFileExists($this->tmpDir . '/storage/uploads/avatars/1.png');
    }

    public function testResetFactoryWipesEverythingExceptMigrationsAndRemovesLockAndUploads(): void
    {
        $this->service->resetFactory();

        $this->assertSame(0, $this->capsule->table('users')->count(), 'users doit être vidé en mode usine');
        $this->assertSame(0, $this->capsule->table('app_settings')->count(), 'app_settings doit être vidé en mode usine');
        $this->assertGreaterThan(0, $this->capsule->table('migrations')->count(), 'la table migrations elle-même ne doit jamais être vidée');
        $this->assertFileDoesNotExist($this->tmpDir . '/storage/installed.lock');
        $this->assertFileDoesNotExist($this->tmpDir . '/storage/uploads/avatars/1.png');
    }

    /**
     * Régression : après un resetFactory(), la table roles restait vide pour
     * toujours, car la migration de seed des rôles par défaut était trackée comme
     * déjà appliquée (table migrations préservée) alors que ses données avaient été
     * vidées — sa garde d'idempotence bloquait tout reseed. Ce test rejoue la vraie
     * migration via MigrationRunner (pas seulement la suppression de sa ligne de
     * tracking) pour vérifier que le reseed complet fonctionne réellement de bout
     * en bout, comme après la réinstallation qui suit un reset usine.
     */
    public function testResetFactoryThenReplayingMigrationsReseedsDefaultRolesAndManagerPermissions(): void
    {
        $this->service->resetFactory();
        $this->assertSame(0, $this->capsule->table('roles')->count(), 'roles doit être vidé par le reset');

        $this->runner->run();

        $roles = $this->capsule->table('roles')->pluck('slug')->all();
        sort($roles);
        $this->assertSame(['employee', 'manager', 'owner'], $roles, 'les 3 rôles système doivent être reseedés');

        $managerId = $this->capsule->table('roles')->where('slug', 'manager')->value('id');
        $managerPermissions = $this->capsule->table('role_permissions')->where('role_id', $managerId)->count();
        $this->assertSame(
            count(\kintai\Core\Auth\PermissionCatalog::MANAGER_DEFAULTS),
            $managerPermissions,
            'le Manager doit récupérer toutes ses permissions par défaut',
        );
    }
}
