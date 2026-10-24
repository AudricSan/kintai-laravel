<?php

declare(strict_types=1);

namespace kintai\Core\Database;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Application;

final class MigrationRunner
{
    use HandlesMigrationExecution;

    private Capsule $capsule;
    private string $migrationsPath;

    public function __construct(Application $app)
    {
        $this->capsule = $app->container()->make(Capsule::class);
        $this->migrationsPath = $app->basePath('database/migrations/php');
        
        if (!is_dir($this->migrationsPath)) {
            mkdir($this->migrationsPath, 0775, true);
        }
    }

    public function run(): int
    {
        $this->ensureMigrationsTable();
        $executed = $this->getExecutedMigrations();
        $files = glob($this->migrationsPath . '/*.php');
        sort($files);

        $count = 0;
        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (in_array($name, $executed)) {
                continue;
            }

            $this->executeMigration($file, $name);
            $count++;
        }

        return $count;
    }

    /** @return string[] Noms des migrations pas encore exécutées, triés par nom de fichier. */
    public function getPendingMigrations(): array
    {
        $this->ensureMigrationsTable();
        $executed = $this->getExecutedMigrations();
        $files = glob($this->migrationsPath . '/*.php');
        sort($files);

        $pending = [];
        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (!in_array($name, $executed)) {
                $pending[] = $name;
            }
        }

        return $pending;
    }

    /**
     * Noms des migrations marquées isSeed() (voir Migration::isSeed()) — utilisé par
     * AppResetService::resetFactory() pour savoir lesquelles doivent rejouer après un
     * reset, sans avoir à connaître aucun nom de migration en dur.
     *
     * @return string[]
     */
    public function getSeedMigrationNames(): array
    {
        $files = glob($this->migrationsPath . '/*.php');
        sort($files);

        $names = [];
        foreach ($files as $file) {
            $migrationClass = require $file;
            $migration = is_object($migrationClass) && $migrationClass instanceof Migration
                ? $migrationClass
                : new $migrationClass($this->capsule);

            if ($migration->isSeed()) {
                $names[] = basename($file, '.php');
            }
        }

        return $names;
    }

    private function ensureMigrationsTable(): void
    {
        $schema = $this->capsule->getConnection()->getSchemaBuilder();
        if (!$schema->hasTable('migrations')) {
            $schema->create('migrations', function ($table) {
                $table->increments('id');
                $table->string('migration')->unique();
                $table->timestamp('executed_at')->useCurrent();
            });
        }
    }

    private function getExecutedMigrations(): array
    {
        return $this->capsule->table('migrations')->pluck('migration')->toArray();
    }

    private function executeMigration(string $file, string $name): void
    {
        $this->runMigrationFile($file, $name);

        $this->capsule->table('migrations')->insert([
            'migration' => $name,
            'executed_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
