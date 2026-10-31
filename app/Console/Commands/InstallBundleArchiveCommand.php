<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BundleInstaller\BundleInstallerService;
use App\Services\BundleInstaller\BundlePermissionGranter;
use App\Services\UpdateService;
use Illuminate\Console\Command;
use ZipArchive;

/**
 * Installe un bundle sur l'instance à partir d'une archive locale (format zipball GitHub), avec le vrai
 * BundleInstallerService : vérification du manifeste, extraction, migrations, droits du gérant, activation.
 * Seuls la résolution de la release et le téléchargement sont remplacés (outil des tests de fumée de la CI).
 */
final class InstallBundleArchiveCommand extends Command
{
    protected $signature = 'kintai:install-bundle-archive {slug : slug du bundle} {archive : archive .zip locale}';

    protected $description = 'Installe un bundle depuis une archive locale (tests de fumée de la CI)';

    public function handle(): int
    {
        $slug = (string) $this->argument('slug');
        $zip  = (string) $this->argument('archive');

        $archive = new ZipArchive();
        if ($archive->open($zip) !== true) {
            $this->output->getErrorStyle()->writeln("Archive illisible : $zip");

            return self::FAILURE;
        }
        $manifest = null;
        for ($i = 0; $i < $archive->numFiles; $i++) {
            if (preg_match('#^[^/]+/bundle\.json$#', (string) $archive->getNameIndex($i))) {
                $manifest = json_decode((string) $archive->getFromIndex($i), true);
            }
        }
        $archive->close();
        $version = (string) ($manifest['version'] ?? '');

        $installer = new BundleInstallerService(
            app(UpdateService::class),
            releaseFetcher: fn(string $repo, string $v): string => 'local://' . $slug,
            zipDownloader: fn(string $url, string $dest): bool => copy($zip, $dest),
            migrator: app('migrator'),
            permissionGranter: app(BundlePermissionGranter::class),
            allowUnpinned: true, // archive locale choisie par l'opérateur (tests de fumée) : aucun registry pour l'épingler
        );
        $installer->install($slug, 'https://github.com/AudricSan/kintai-bundle-' . $slug, $version);
        $error = $installer->getLastError();
        $this->line($slug . ' ' . $version . ' : ' . ($error ?? 'installé'));

        return $error === null ? self::SUCCESS : self::FAILURE;
    }
}
