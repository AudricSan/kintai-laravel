<?php
declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Database;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Database\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class MigrationRunnerTest extends TestCase
{
    private string $migrationsPath;

    protected function setUp(): void
    {
        $this->migrationsPath = sys_get_temp_dir() . '/kintai_migrations_test_' . uniqid();
        mkdir($this->migrationsPath, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->migrationsPath . '/*.php') as $file) {
            unlink($file);
        }
        rmdir($this->migrationsPath);
    }

    private function makeRunner(Capsule $capsule): MigrationRunner
    {
        $runner = (new \ReflectionClass(MigrationRunner::class))->newInstanceWithoutConstructor();

        $capsuleProp = new \ReflectionProperty(MigrationRunner::class, 'capsule');
        $capsuleProp->setAccessible(true);
        $capsuleProp->setValue($runner, $capsule);

        $pathProp = new \ReflectionProperty(MigrationRunner::class, 'migrationsPath');
        $pathProp->setAccessible(true);
        $pathProp->setValue($runner, $this->migrationsPath);

        return $runner;
    }

    private function writeMigration(string $name, string $body): void
    {
        file_put_contents($this->migrationsPath . "/{$name}.php", "<?php\n" . $body);
    }

    public function testAlreadyPresentColumnIsTrackedInsteadOfFailing(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $schema = $capsule->getConnection()->getSchemaBuilder();
        $schema->create('users', function ($table) {
            $table->increments('id');
            $table->string('furigana')->nullable();
        });

        $this->writeMigration('2026_06_09_000033_extend_users_table', <<<'PHP'
use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class($this->capsule) extends Migration {
    public function up(): void
    {
        $this->schema()->table('users', function (Blueprint $table) {
            $table->string('furigana')->nullable();
        });
    }

    public function down(): void
    {
    }
};
PHP);

        $runner = $this->makeRunner($capsule);

        $count = $runner->run();

        $this->assertSame(1, $count);
        $this->assertSame(
            ['2026_06_09_000033_extend_users_table'],
            $capsule->table('migrations')->pluck('migration')->toArray(),
        );
    }

    public function testAlreadySeededUniqueRowIsTrackedInsteadOfFailing(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $schema = $capsule->getConnection()->getSchemaBuilder();
        $schema->create('languages', function ($table) {
            $table->increments('id');
            $table->string('code')->unique();
        });
        $capsule->table('languages')->insert(['code' => 'fr']);

        $this->writeMigration('2026_07_04_000000_create_languages_and_translations_tables', <<<'PHP'
use kintai\Core\Database\Migration;

return new class($this->capsule) extends Migration {
    public function up(): void
    {
        $this->capsule->table('languages')->insert(['code' => 'fr']);
    }

    public function down(): void
    {
    }
};
PHP);

        $runner = $this->makeRunner($capsule);

        $count = $runner->run();

        $this->assertSame(1, $count);
        $this->assertSame(
            ['2026_07_04_000000_create_languages_and_translations_tables'],
            $capsule->table('migrations')->pluck('migration')->toArray(),
        );
    }

    /**
     * Régression : scripts/db-migrate.php --dry-run était documenté mais jamais
     * implémenté (le script ne lisait aucun argument CLI).
     */
    public function testGetPendingMigrationsListsUnexecutedMigrationsWithoutRunningThem(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $this->writeMigration('2026_01_01_000000_first', <<<'PHP'
use kintai\Core\Database\Migration;

return new class($this->capsule) extends Migration {
    public function up(): void
    {
        $this->schema()->create('widgets', function ($table) {
            $table->increments('id');
        });
    }

    public function down(): void
    {
    }
};
PHP);
        $this->writeMigration('2026_01_02_000000_second', <<<'PHP'
use kintai\Core\Database\Migration;

return new class($this->capsule) extends Migration {
    public function up(): void
    {
        $this->schema()->create('gadgets', function ($table) {
            $table->increments('id');
        });
    }

    public function down(): void
    {
    }
};
PHP);

        $runner = $this->makeRunner($capsule);

        $this->assertSame(
            ['2026_01_01_000000_first', '2026_01_02_000000_second'],
            $runner->getPendingMigrations(),
        );

        // getPendingMigrations() ne doit pas exécuter les migrations.
        $this->assertFalse($capsule->getConnection()->getSchemaBuilder()->hasTable('widgets'));

        $runner->run();

        $this->assertSame([], $runner->getPendingMigrations());
    }

    public function testGetSeedMigrationNamesReturnsOnlyMigrationsMarkedAsSeed(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $this->writeMigration('2026_01_01_000000_plain', <<<'PHP'
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
        $this->writeMigration('2026_01_02_000000_seed', <<<'PHP'
use kintai\Core\Database\Migration;

return new class($this->capsule) extends Migration {
    public function isSeed(): bool
    {
        return true;
    }

    public function up(): void
    {
    }

    public function down(): void
    {
    }
};
PHP);

        $runner = $this->makeRunner($capsule);

        $this->assertSame(['2026_01_02_000000_seed'], $runner->getSeedMigrationNames());
    }

    public function testUnrelatedFailureStillThrows(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $this->writeMigration('2026_01_01_000000_broken_migration', <<<'PHP'
use kintai\Core\Database\Migration;

return new class($this->capsule) extends Migration {
    public function up(): void
    {
        $this->schema()->table('table_that_does_not_exist', function ($table) {
            $table->string('foo')->nullable();
        });
    }

    public function down(): void
    {
    }
};
PHP);

        $runner = $this->makeRunner($capsule);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('2026_01_01_000000_broken_migration failed');

        $runner->run();
    }
}
