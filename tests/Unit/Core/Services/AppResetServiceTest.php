<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Services\AppResetService;
use PHPUnit\Framework\TestCase;

final class AppResetServiceTest extends TestCase
{
    private string $storageDir;

    protected function setUp(): void
    {
        // AppResetService::resetFactory() supprime storage_path('installed.lock') — sans cet
        // isolement, elle supprimait le vrai storage/installed.lock du projet à chaque
        // exécution de la suite de tests (bug découvert le 2026-08-07, voir CHANGELOG).
        $this->storageDir = sys_get_temp_dir() . '/kintai_reset_storage_test_' . uniqid();
        mkdir($this->storageDir, 0775, true);
        putenv('KINTAI_STORAGE_PATH=' . $this->storageDir);
    }

    protected function tearDown(): void
    {
        putenv('KINTAI_STORAGE_PATH');
        foreach (glob($this->storageDir . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->storageDir);
    }

    private function makeCapsule(): Capsule
    {
        $capsule = kintai_test_database();

        $schema = $capsule->getConnection()->getSchemaBuilder();
        $schema->create('roles', function ($table) {
            $table->increments('id');
            $table->string('slug');
        });
        $schema->create('users', function ($table) {
            $table->increments('id');
        });
        $schema->create('migrations', function ($table) {
            $table->increments('id');
            $table->string('migration');
            $table->integer('batch');
        });
        $capsule->table('migrations')->insert(['migration' => '2026_01_01_000000_create_roles', 'batch' => 1]);
        $capsule->table('roles')->insert(['slug' => 'owner']);
        $capsule->table('users')->insert([]);

        return $capsule;
    }

    public function testResetFactoryClearsOperationalTablesButKeepsTheMigrationLog(): void
    {
        $capsule = $this->makeCapsule();

        $wiped = (new AppResetService($capsule))->resetFactory();

        $this->assertContains('users', $wiped);
        $this->assertContains('roles', $wiped);
        $this->assertNotContains('migrations', $wiped);
        $this->assertSame(0, $capsule->table('users')->count());
        $this->assertSame(0, $capsule->table('roles')->count(), 'roles doit être vidé par le reset (rejoué par db:seed)');
        $this->assertSame(1, $capsule->table('migrations')->count(), 'le journal des migrations n\'est jamais vidé');
    }
}
