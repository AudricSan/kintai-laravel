<?php

/**
 * Usage:
 *   php scripts/db-migrate.php              # applique les migrations en attente
 *   php scripts/db-migrate.php --dry-run    # liste les migrations en attente sans les exécuter
 */

declare(strict_types=1);

use kintai\Core\Application;
use kintai\Core\Database\BundleMigrationRunner;
use kintai\Core\Database\MigrationRunner;
use kintai\Core\Repositories\InstalledBundleRepositoryInterface;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../bootstrap/standalone.php';

$dryRun = in_array('--dry-run', $argv, true);

$app = new Application(dirname(__DIR__));
$app->boot();

$runner = new MigrationRunner($app);
$bundleRunner = new BundleMigrationRunner($app);
$installedBundles = $app->container()->make(InstalledBundleRepositoryInterface::class);

if ($dryRun) {
    $pending = $runner->getPendingMigrations();
    if ($pending === []) {
        echo "No pending Core migrations.\n";
    } else {
        echo count($pending) . " pending Core migration(s):\n";
        foreach ($pending as $name) {
            echo "  - {$name}\n";
        }
    }

    foreach ($installedBundles->all() as $bundle) {
        $migrationsPath = storage_path("bundles/{$bundle['slug']}/{$bundle['active_version']}/database/migrations");
        $bundlePending = $bundleRunner->getPendingFor($bundle['slug'], $migrationsPath);
        if ($bundlePending === []) {
            continue;
        }
        echo count($bundlePending) . " pending migration(s) for bundle {$bundle['slug']}:\n";
        foreach ($bundlePending as $name) {
            echo "  - {$name}\n";
        }
    }
    exit(0);
}

$count = $runner->run();
echo "{$count} Core migration(s) applied.\n";

foreach ($installedBundles->all() as $bundle) {
    $migrationsPath = storage_path("bundles/{$bundle['slug']}/{$bundle['active_version']}/database/migrations");
    $applied = $bundleRunner->runPendingFor($bundle['slug'], $migrationsPath);
    if ($applied !== []) {
        echo count($applied) . " migration(s) applied for bundle {$bundle['slug']}.\n";
    }
}
