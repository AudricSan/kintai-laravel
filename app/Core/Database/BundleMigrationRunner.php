<?php

declare(strict_types=1);

namespace kintai\Core\Database;

use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Core\Application;

/**
 * Variante de MigrationRunner pour les migrations fournies par un bundle
 * distribué (dossier optionnel `database/migrations/` à la racine du bundle,
 * voir docs/creating-a-bundle.md). Même format de fichier de migration, même
 * tolérance aux erreurs "déjà appliqué", mais tracking dans la table
 * `bundle_migrations` (scopée par `bundle_slug`) plutôt que `migrations` (Core)
 * — jamais mélangé avec le suivi des migrations Core, et permet un nettoyage
 * ciblé par bundle (voir BundleInstallerService::uninstall()).
 */
final class BundleMigrationRunner
{
    use HandlesMigrationExecution;

    private Capsule $capsule;

    public function __construct(Application $app)
    {
        $this->capsule = $app->container()->make(Capsule::class);
    }

    /** @return string[] Noms des migrations appliquées lors de cet appel. */
    public function runPendingFor(string $bundleSlug, string $migrationsPath): array
    {
        $this->ensureBundleMigrationsTable();
        $executed = $this->getExecutedMigrations($bundleSlug);

        $applied = [];
        foreach ($this->migrationFiles($migrationsPath) as $file) {
            $name = basename($file, '.php');
            if (in_array($name, $executed, true)) {
                continue;
            }

            $this->executeMigration($bundleSlug, $file, $name);
            $applied[] = $name;
        }

        return $applied;
    }

    /** @return string[] Noms des migrations pas encore exécutées, triés par nom de fichier. */
    public function getPendingFor(string $bundleSlug, string $migrationsPath): array
    {
        $this->ensureBundleMigrationsTable();
        $executed = $this->getExecutedMigrations($bundleSlug);

        $pending = [];
        foreach ($this->migrationFiles($migrationsPath) as $file) {
            $name = basename($file, '.php');
            if (!in_array($name, $executed, true)) {
                $pending[] = $name;
            }
        }

        return $pending;
    }

    /**
     * Retire tout le suivi d'un bundle. Les migrations elles-mêmes restent
     * idempotentes (gardes hasTable()/hasColumn() dans up()), donc une
     * réinstallation ultérieure les rejoue sans risque — mais aucune table
     * métier n'est supprimée ici (limitation assumée, voir
     * docs/creating-a-bundle.md "No uninstall flow yet").
     */
    public function forgetBundle(string $bundleSlug): void
    {
        $this->ensureBundleMigrationsTable();
        $this->capsule->table('bundle_migrations')->where('bundle_slug', $bundleSlug)->delete();
    }

    /** @return string[] */
    private function migrationFiles(string $migrationsPath): array
    {
        if (!is_dir($migrationsPath)) {
            return [];
        }

        $files = glob($migrationsPath . '/*.php');
        sort($files);

        return $files;
    }

    private function ensureBundleMigrationsTable(): void
    {
        $schema = $this->capsule->getConnection()->getSchemaBuilder();
        if (!$schema->hasTable('bundle_migrations')) {
            $schema->create('bundle_migrations', function ($table) {
                $table->increments('id');
                $table->string('bundle_slug');
                $table->string('migration');
                $table->timestamp('executed_at')->useCurrent();
                $table->unique(['bundle_slug', 'migration']);
            });
        }
    }

    private function getExecutedMigrations(string $bundleSlug): array
    {
        return $this->capsule->table('bundle_migrations')
            ->where('bundle_slug', $bundleSlug)
            ->pluck('migration')
            ->toArray();
    }

    private function executeMigration(string $bundleSlug, string $file, string $name): void
    {
        $this->runMigrationFile($file, $name);

        $this->capsule->table('bundle_migrations')->insert([
            'bundle_slug' => $bundleSlug,
            'migration' => $name,
            'executed_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
