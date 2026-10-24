<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Database\MigrationRunner;
use kintai\Core\Services\AppResetService;
use PHPUnit\Framework\TestCase;

final class AppResetServiceTest extends TestCase
{
    private string $migrationsPath;
    private string $storageDir;

    protected function setUp(): void
    {
        $this->migrationsPath = sys_get_temp_dir() . '/kintai_reset_migrations_test_' . uniqid();
        mkdir($this->migrationsPath, 0775, true);

        // AppResetService::resetFactory() supprime storage_path('installed.lock') — sans cet
        // isolement, elle supprimait le vrai storage/installed.lock du projet à chaque
        // exécution de la suite de tests (bug découvert le 2026-08-07, voir CHANGELOG).
        $this->storageDir = sys_get_temp_dir() . '/kintai_reset_storage_test_' . uniqid();
        mkdir($this->storageDir, 0775, true);
        putenv('KINTAI_STORAGE_PATH=' . $this->storageDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->migrationsPath . '/*.php') as $file) {
            unlink($file);
        }
        rmdir($this->migrationsPath);

        putenv('KINTAI_STORAGE_PATH');
        foreach (glob($this->storageDir . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->storageDir);
    }

    private function writeMigration(string $name, string $body): void
    {
        file_put_contents($this->migrationsPath . "/{$name}.php", "<?php\n" . $body);
    }

    /** @return array{0: Capsule, 1: MigrationRunner} */
    private function makeCapsuleAndRunner(): array
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $schema = $capsule->getConnection()->getSchemaBuilder();
        $schema->create('roles', function ($table) {
            $table->increments('id');
            $table->string('slug');
        });
        $schema->create('users', function ($table) {
            $table->increments('id');
        });

        $this->writeMigration('2026_01_01_000000_seed_roles', <<<'PHP'
use kintai\Core\Database\Migration;

return new class($this->capsule) extends Migration {
    public function isSeed(): bool
    {
        return true;
    }

    public function up(): void
    {
        $roles = $this->capsule->table('roles');
        if ($roles->where('slug', 'owner')->exists()) {
            return;
        }
        $roles->insert(['slug' => 'owner']);
    }

    public function down(): void
    {
    }
};
PHP);

        $this->writeMigration('2026_01_02_000000_plain_schema_change', <<<'PHP'
use kintai\Core\Database\Migration;

return new class($this->capsule) extends Migration {
    public function up(): void
    {
    }

    public function down(): void
    {
    }
};
PHP);

        $runner = (new \ReflectionClass(MigrationRunner::class))->newInstanceWithoutConstructor();
        $capsuleProp = new \ReflectionProperty(MigrationRunner::class, 'capsule');
        $capsuleProp->setAccessible(true);
        $capsuleProp->setValue($runner, $capsule);
        $pathProp = new \ReflectionProperty(MigrationRunner::class, 'migrationsPath');
        $pathProp->setAccessible(true);
        $pathProp->setValue($runner, $this->migrationsPath);
        $runner->run();

        return [$capsule, $runner];
    }

    public function testGetSeedMigrationNamesOnlyReturnsMigrationsMarkedAsSeed(): void
    {
        [, $runner] = $this->makeCapsuleAndRunner();

        $this->assertSame(['2026_01_01_000000_seed_roles'], $runner->getSeedMigrationNames());
    }

    /**
     * Régression : resetFactory() vidait roles mais préservait la table migrations,
     * donc la migration de seed restait trackée comme déjà appliquée et sa garde
     * d'idempotence bloquait tout reseed pour toujours.
     */
    public function testResetFactoryUntracksSeedMigrationSoItReplaysAndReseedsData(): void
    {
        [$capsule, $runner] = $this->makeCapsuleAndRunner();
        $capsule->table('users')->insert([]);

        $service = new AppResetService($capsule, $runner);
        $service->resetFactory();

        $this->assertSame(0, $capsule->table('roles')->count(), 'roles doit être vidé par le reset');
        $this->assertSame(
            ['2026_01_02_000000_plain_schema_change'],
            $capsule->table('migrations')->pluck('migration')->toArray(),
            'seule la migration de seed doit être untrackée, pas la migration de schéma pur',
        );

        $runner->run();

        $this->assertSame(1, $capsule->table('roles')->count(), 'la migration de seed doit avoir rejoué et reseedé roles');
    }

    public function testResetFactoryClearsOperationalTables(): void
    {
        [$capsule, $runner] = $this->makeCapsuleAndRunner();
        $capsule->table('users')->insert([]);

        $service = new AppResetService($capsule, $runner);
        $wiped = $service->resetFactory();

        $this->assertContains('users', $wiped);
        $this->assertSame(0, $capsule->table('users')->count());
    }
}
