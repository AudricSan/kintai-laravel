<?php

declare(strict_types=1);

/**
 * Installe un bundle sur l'instance à partir d'une archive locale (format zipball GitHub), avec le vrai
 * BundleInstallerService : vérification du manifeste, extraction, migrations, droits du gérant, activation.
 * Seuls la résolution de la release et le téléchargement sont remplacés (paramètres prévus pour les tests).
 *
 * Usage : php scripts/ci/install-bundle-archive.php <slug> <archive.zip>
 */

[$_, $slug, $zip] = $argv + [null, null, null];
if ($slug === null || $zip === null) {
    fwrite(STDERR, "Usage : install-bundle-archive.php <slug> <archive.zip>\n");
    exit(2);
}

define('BASE_PATH', dirname(__DIR__, 2));
require BASE_PATH . '/vendor/autoload.php';
require BASE_PATH . '/bootstrap/standalone.php';
require BASE_PATH . '/app/Core/helpers.php';

use kintai\Core\Database\BundleMigrationRunner;
use kintai\Core\InstalledBundleAutoloader;
use kintai\Core\Repositories\InstalledBundleRepositoryInterface;
use kintai\Core\Services\BundleInstaller\BundleInstallerService;
use kintai\Core\Services\BundleInstaller\BundlePermissionGranter;
use kintai\Core\Services\UpdateService;

kintai_load_env_file(BASE_PATH);
(new InstalledBundleAutoloader())->register();
$app = require BASE_PATH . '/bootstrap/boot.php';
$c = $app;

$z = new ZipArchive();
if ($z->open($zip) !== true) {
    fwrite(STDERR, "Archive illisible : $zip\n");
    exit(1);
}
$manifest = null;
for ($i = 0; $i < $z->numFiles; $i++) {
    if (preg_match('#^[^/]+/bundle\.json$#', (string) $z->getNameIndex($i))) {
        $manifest = json_decode((string) $z->getFromIndex($i), true);
    }
}
$z->close();
$version = (string) ($manifest['version'] ?? '');

$installer = new BundleInstallerService(
    $c->make(UpdateService::class),
    $c->make(InstalledBundleRepositoryInterface::class),
    releaseFetcher: fn(string $repo, string $v): string => 'local://' . $slug,
    zipDownloader: fn(string $url, string $dest): bool => copy($zip, $dest),
    migrationRunner: $c->make(BundleMigrationRunner::class),
    permissionGranter: $c->make(BundlePermissionGranter::class),
);
$result = $installer->install($slug, 'https://github.com/AudricSan/kintai-bundle-' . $slug, $version);
$error  = $installer->getLastError();
echo $slug, ' ', $version, ' : ', $error ?? 'installé', PHP_EOL;
exit($error === null ? 0 : 1);
