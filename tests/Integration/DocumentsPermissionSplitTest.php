<?php

declare(strict_types=1);

namespace kintai\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Database\Migration;
use kintai\Core\Database\MigrationRunner;
use PHPUnit\Framework\TestCase;

/**
 * `documents.*` mélangeait HiringReport et ResignationReport, deux bundles
 * sans rapport : un rôle ne pouvait pas accorder l'un sans l'autre. La
 * migration 2026_09_09_000001_split_documents_into_hiring_and_resignation_reports
 * doit reporter aux rôles existants l'accès équivalent sur les deux
 * nouvelles catégories, puis nettoyer les lignes documents.* devenues mortes.
 */
final class DocumentsPermissionSplitTest extends TestCase
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

    private function reRunSplitMigration(Capsule $capsule): void
    {
        $file = dirname(__DIR__, 2) . '/database/migrations/php/2026_09_09_000001_split_documents_into_hiring_and_resignation_reports.php';
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

    public function testMirrorsEachDocumentsActionOntoBothNewCategoriesAndDropsTheOldKey(): void
    {
        $capsule = $this->migratedCapsule();
        $conn    = $capsule->getConnection();

        $roleId = $this->createCustomRole($capsule, 'hr-docs-test');
        $conn->table('role_permissions')->insert([
            ['role_id' => $roleId, 'permission_key' => 'documents.view'],
            ['role_id' => $roleId, 'permission_key' => 'documents.delete'],
        ]);

        $this->reRunSplitMigration($capsule);

        $granted = $conn->table('role_permissions')->where('role_id', $roleId)->pluck('permission_key')->all();
        sort($granted);

        $this->assertSame(
            ['hiring_reports.delete', 'hiring_reports.view', 'resignation_reports.delete', 'resignation_reports.view'],
            $granted
        );
    }

    public function testIsIdempotent(): void
    {
        $capsule = $this->migratedCapsule();
        $conn    = $capsule->getConnection();

        $roleId = $this->createCustomRole($capsule, 'idempotent-hr-test');
        $conn->table('role_permissions')->insert([
            'role_id' => $roleId, 'permission_key' => 'documents.update',
        ]);

        $this->reRunSplitMigration($capsule);
        $this->reRunSplitMigration($capsule);

        $count = $conn->table('role_permissions')
            ->where('role_id', $roleId)
            ->where('permission_key', 'hiring_reports.update')
            ->count();

        $this->assertSame(1, $count);
    }

    public function testFullMigrationRunLeavesNoDocumentsKeyBehind(): void
    {
        // documents.* est accordé au Manager seedé par défaut (via
        // 2026_07_14_000005) : le run complet des migrations doit l'avoir
        // entièrement basculé vers hiring_reports.*/resignation_reports.*.
        $capsule = $this->migratedCapsule();

        $remaining = $capsule->getConnection()
            ->table('role_permissions')
            ->where('permission_key', 'like', 'documents.%')
            ->count();

        $this->assertSame(0, $remaining);
    }
}
