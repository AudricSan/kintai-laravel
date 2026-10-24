<?php
declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Database;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Database\BundleMigrationRunner;
use PHPUnit\Framework\TestCase;

final class BundleMigrationRunnerTest extends TestCase
{
    private string $migrationsPath;

    protected function setUp(): void
    {
        $this->migrationsPath = sys_get_temp_dir() . '/kintai_bundle_migrations_test_' . uniqid();
        mkdir($this->migrationsPath, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->migrationsPath . '/*.php') as $file) {
            unlink($file);
        }
        rmdir($this->migrationsPath);
    }

    private function makeRunner(Capsule $capsule): BundleMigrationRunner
    {
        $runner = (new \ReflectionClass(BundleMigrationRunner::class))->newInstanceWithoutConstructor();

        $capsuleProp = new \ReflectionProperty(BundleMigrationRunner::class, 'capsule');
        $capsuleProp->setAccessible(true);
        $capsuleProp->setValue($runner, $capsule);

        return $runner;
    }

    private function writeMigration(string $name, string $body): void
    {
        file_put_contents($this->migrationsPath . "/{$name}.php", "<?php\n" . $body);
    }

    private function freshCapsule(): Capsule
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        return $capsule;
    }

    public function testRunPendingForCreatesTheTableAndTracksItUnderTheBundleSlug(): void
    {
        $capsule = $this->freshCapsule();

        $this->writeMigration('2026_09_25_000001_create_notebook_entries_table', <<<'PHP'
use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class($this->capsule) extends Migration {
    public function up(): void
    {
        $this->schema()->create('notebook_entries', function (Blueprint $table) {
            $table->increments('id');
            $table->text('content');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('notebook_entries');
    }
};
PHP);

        $runner = $this->makeRunner($capsule);
        $applied = $runner->runPendingFor('notebook', $this->migrationsPath);

        $this->assertSame(['2026_09_25_000001_create_notebook_entries_table'], $applied);
        $this->assertTrue($capsule->getConnection()->getSchemaBuilder()->hasTable('notebook_entries'));
        $this->assertSame(
            ['notebook'],
            $capsule->table('bundle_migrations')->pluck('bundle_slug')->toArray(),
        );
    }

    public function testRunPendingForIsIdempotentAcrossRuns(): void
    {
        $capsule = $this->freshCapsule();

        $this->writeMigration('2026_09_25_000001_create_notebook_entries_table', <<<'PHP'
use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class($this->capsule) extends Migration {
    public function up(): void
    {
        $this->schema()->create('notebook_entries', function (Blueprint $table) {
            $table->increments('id');
        });
    }

    public function down(): void
    {
    }
};
PHP);

        $runner = $this->makeRunner($capsule);
        $runner->runPendingFor('notebook', $this->migrationsPath);
        $secondRun = $runner->runPendingFor('notebook', $this->migrationsPath);

        $this->assertSame([], $secondRun);
        $this->assertSame(1, $capsule->table('bundle_migrations')->count());
    }

    /**
     * Deux bundles différents avec un fichier de migration au même nom ne
     * doivent jamais collisionner (contrairement à la table `migrations` du
     * Core, dont la colonne `migration` est unique globalement).
     */
    public function testTwoBundlesWithTheSameMigrationFileNameDoNotCollide(): void
    {
        $capsule = $this->freshCapsule();

        $this->writeMigration('2026_09_25_000001_create_table', <<<'PHP'
use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if (!$this->schema()->hasTable('bundle_a_table')) {
            $this->schema()->create('bundle_a_table', function (Blueprint $table) {
                $table->increments('id');
            });
        }
    }

    public function down(): void
    {
    }
};
PHP);

        $runner = $this->makeRunner($capsule);
        $runner->runPendingFor('bundle-a', $this->migrationsPath);
        $appliedForB = $runner->runPendingFor('bundle-b', $this->migrationsPath);

        $this->assertSame(['2026_09_25_000001_create_table'], $appliedForB);
        $this->assertSame(2, $capsule->table('bundle_migrations')->count());
    }

    public function testGetPendingForListsWithoutRunning(): void
    {
        $capsule = $this->freshCapsule();

        $this->writeMigration('2026_09_25_000001_first', <<<'PHP'
use kintai\Core\Database\Migration;

return new class($this->capsule) extends Migration {
    public function up(): void
    {
        $this->schema()->create('some_table', function ($table) {
            $table->increments('id');
        });
    }

    public function down(): void
    {
    }
};
PHP);

        $runner = $this->makeRunner($capsule);

        $this->assertSame(['2026_09_25_000001_first'], $runner->getPendingFor('notebook', $this->migrationsPath));
        $this->assertFalse($capsule->getConnection()->getSchemaBuilder()->hasTable('some_table'));

        $runner->runPendingFor('notebook', $this->migrationsPath);

        $this->assertSame([], $runner->getPendingFor('notebook', $this->migrationsPath));
    }

    public function testGetPendingForReturnsEmptyWhenTheDirectoryDoesNotExist(): void
    {
        $capsule = $this->freshCapsule();
        $runner = $this->makeRunner($capsule);

        $this->assertSame([], $runner->getPendingFor('notebook', $this->migrationsPath . '/does-not-exist'));
    }

    public function testForgetBundleRemovesOnlyThatBundlesTrackingRows(): void
    {
        $capsule = $this->freshCapsule();

        $this->writeMigration('2026_09_25_000001_create_table', <<<'PHP'
use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if (!$this->schema()->hasTable('shared_name_table')) {
            $this->schema()->create('shared_name_table', function (Blueprint $table) {
                $table->increments('id');
            });
        }
    }

    public function down(): void
    {
    }
};
PHP);

        $runner = $this->makeRunner($capsule);
        $runner->runPendingFor('bundle-a', $this->migrationsPath);
        $runner->runPendingFor('bundle-b', $this->migrationsPath);

        $runner->forgetBundle('bundle-a');

        $remaining = $capsule->table('bundle_migrations')->pluck('bundle_slug')->toArray();
        $this->assertSame(['bundle-b'], $remaining);
    }

    public function testUnrelatedFailureStillThrows(): void
    {
        $capsule = $this->freshCapsule();

        $this->writeMigration('2026_09_25_000001_broken', <<<'PHP'
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
        $this->expectExceptionMessage('2026_09_25_000001_broken failed');

        $runner->runPendingFor('notebook', $this->migrationsPath);
    }
}
