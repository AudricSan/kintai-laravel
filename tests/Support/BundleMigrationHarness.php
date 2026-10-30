<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Joue les migrations d'un bundle avec le migrateur de Laravel, sur la base de test courante (kintai_test_database()),
 * comme le fait BundleServiceProvider via loadMigrationsFrom().
 */
final class BundleMigrationHarness
{
    /** @return list<string> noms des migrations jouées, dans l'ordre */
    public function runPendingFor(string $slug, string $path): array
    {
        // Les clés étrangères d'un bundle visent des tables du Core : migrations du Core d'abord, sauf si le test a
        // préparé lui-même ses tables.
        if (!\Illuminate\Support\Facades\Schema::hasTable('users')) {
            $_SERVER['PHP_SELF'] ??= 'artisan';
            $GLOBALS['kintai_test_app']->make(\Illuminate\Contracts\Console\Kernel::class)->call('migrate', [
                '--path' => base_path('database/migrations'), '--realpath' => true, '--force' => true,
            ]);
        }
        $migrator = app('migrator');
        if (!$migrator->repositoryExists()) {
            $migrator->getRepository()->createRepository();
        }

        $ran = array_values(array_map(
            static fn(string $file): string => basename($file, '.php'),
            $migrator->run([$path]),
        ));
        // Les tests insèrent des lignes sans créer le magasin ou l'utilisateur qu'elles visent.
        \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = OFF');

        return $ran;
    }
}
