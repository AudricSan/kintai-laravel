<?php

declare(strict_types=1);

namespace kintai\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Database\Migration;
use kintai\Core\Database\MigrationRunner;
use PHPUnit\Framework\TestCase;

/**
 * Le bundle StorePhoto réutilisait `documents.*` faute de catégorie RBAC
 * dédiée ; `photos.*` devient sa propre catégorie (voir PermissionCatalog).
 * La migration 2026_09_09_000000_grant_photo_permissions_to_existing_roles
 * doit reporter aux rôles existants exactement l'accès qu'ils avaient déjà
 * via documents.*, pour que ce changement de catégorie ne prive personne
 * silencieusement de l'accès aux photos.
 */
final class PhotoPermissionsBackfillTest extends TestCase
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

    private function reRunBackfillMigration(Capsule $capsule): void
    {
        $file = dirname(__DIR__, 2) . '/database/migrations/php/2026_09_09_000000_grant_photo_permissions_to_existing_roles.php';
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
        // Instance existante : au moins un compte (sur une installation neuve, le rattrapage ne fait rien).
        if ($conn->table('users')->count() === 0) {
            $conn->table('users')->insert(['email' => 'owner@kintai.test', 'password_hash' => 'x', 'first_name' => 'O', 'last_name' => 'W', 'display_name' => 'O W']);
        }
        $conn->table('roles')->insert([
            'name' => $slug, 'slug' => $slug, 'is_system' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return (int) $conn->table('roles')->where('slug', $slug)->value('id');
    }

    /** Installation neuve (aucun compte au moment des migrations) : rien n'est accordé, les droits viennent à l'installation du bundle. */
    public function testDoesNothingOnAFreshInstall(): void
    {
        $capsule = $this->migratedCapsule();
        $conn = $capsule->getConnection();
        $conn->table('roles')->insert(['name' => 'fresh', 'slug' => 'fresh', 'is_system' => 0, 'created_at' => date('Y-m-d H:i:s')]);
        $roleId = (int) $conn->table('roles')->where('slug', 'fresh')->value('id');
        $conn->table('role_permissions')->insert(['role_id' => $roleId, 'permission_key' => 'documents.view']);

        $this->reRunBackfillMigration($capsule);

        $this->assertSame(0, $conn->table('role_permissions')->where('permission_key', 'like', 'photos.%')->count());
    }

    public function testMirrorsEachDocumentsActionOntoPhotos(): void
    {
        $capsule = $this->migratedCapsule();
        $conn    = $capsule->getConnection();

        $roleId = $this->createCustomRole($capsule, 'photo-manager-test');
        $conn->table('role_permissions')->insert([
            ['role_id' => $roleId, 'permission_key' => 'documents.view'],
            ['role_id' => $roleId, 'permission_key' => 'documents.create'],
        ]);

        $this->reRunBackfillMigration($capsule);

        $granted = $conn->table('role_permissions')->where('role_id', $roleId)->pluck('permission_key')->all();
        sort($granted);

        $this->assertSame(
            ['documents.create', 'documents.view', 'photos.create', 'photos.view'],
            $granted
        );
    }

    public function testDoesNotGrantActionsNotHeldOnDocuments(): void
    {
        $capsule = $this->migratedCapsule();
        $conn    = $capsule->getConnection();

        $roleId = $this->createCustomRole($capsule, 'view-only-test');
        $conn->table('role_permissions')->insert([
            'role_id' => $roleId, 'permission_key' => 'documents.view',
        ]);

        $this->reRunBackfillMigration($capsule);

        $granted = $conn->table('role_permissions')->where('role_id', $roleId)->pluck('permission_key')->all();

        $this->assertNotContains('photos.delete', $granted);
        $this->assertNotContains('photos.update', $granted);
        $this->assertNotContains('photos.create', $granted);
    }

    public function testIsIdempotent(): void
    {
        $capsule = $this->migratedCapsule();
        $conn    = $capsule->getConnection();

        $roleId = $this->createCustomRole($capsule, 'idempotent-test');
        $conn->table('role_permissions')->insert([
            'role_id' => $roleId, 'permission_key' => 'documents.delete',
        ]);

        $this->reRunBackfillMigration($capsule);
        $this->reRunBackfillMigration($capsule);

        $count = $conn->table('role_permissions')
            ->where('role_id', $roleId)
            ->where('permission_key', 'photos.delete')
            ->count();

        $this->assertSame(1, $count);
    }
}
