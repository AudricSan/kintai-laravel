<?php

namespace App\Backups;

use Throwable;

/**
 * Relit une archive de sauvegarde : contrôle (`check()`), puis remplacement de la base et des fichiers téléversés
 * (`restore()`). Ni maintenance, ni sauvegarde de sécurité, ni migrations ici : c'est le rôle de l'action `RestoreBackup`.
 */
class BackupRestorer
{
    public function __construct(private readonly BackupManager $manager) {}

    /**
     * Vérifie l'archive (entrées attendues, manifeste, pilote) sans rien modifier.
     *
     * @return array<string, mixed> Le manifeste.
     *
     * @throws BackupException
     */
    public function check(BackupInfo $backup): array
    {
        $archive = BackupArchive::inspect($this->manager->managedPath($backup));
        $archive['zip']->close();

        $this->assertDriver($archive['manifest']);

        return $archive['manifest'];
    }

    /**
     * Remplace la base, puis les fichiers téléversés, par ceux de l'archive.
     *
     * @throws BackupException
     */
    public function restore(BackupInfo $backup): void
    {
        @set_time_limit(0);

        $archive = BackupArchive::inspect($this->manager->managedPath($backup));
        $zip = $archive['zip'];
        $work = $this->manager->directory().DIRECTORY_SEPARATOR.'.restore_'.bin2hex(random_bytes(6));

        try {
            $this->assertDriver($archive['manifest']);

            if (! mkdir($work, 0775)) {
                throw new BackupException(__('The backups folder cannot be written.'));
            }

            $dumper = $this->manager->dumper();
            $database = $work.DIRECTORY_SEPARATOR.$dumper->fileName();
            BackupArchive::extract($zip, $dumper->fileName(), $database);

            $dumper->restore($database);

            $this->restoreFiles($archive['files'], $zip, $work);
        } catch (BackupException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new BackupException(__('The backup could not be restored.'), 0, $e);
        } finally {
            $zip->close();
            BackupManager::removeDirectory($work);
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     *
     * @throws BackupException
     */
    private function assertDriver(array $manifest): void
    {
        $current = $this->manager->dumper()->driver();

        if ($manifest['driver'] !== $current) {
            throw new BackupException(__('This backup was made with another database type (:backup); this installation uses :current.', [
                'backup' => (string) $manifest['driver'],
                'current' => $current,
            ]));
        }
    }

    /**
     * Les fichiers de l'archive remplacent ceux du disque : ceux qui n'y sont pas sont supprimés (la base restaurée ne les référence plus).
     *
     * @param  array<string, int>  $entries
     */
    private function restoreFiles(array $entries, \ZipArchive $zip, string $work): void
    {
        $directory = $this->manager->avatarsDirectory();

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new BackupException(__('The uploaded files could not be restored.'));
        }

        $restored = [];

        foreach (array_keys($entries) as $entry) {
            if (! str_starts_with($entry, BackupArchive::FILES_PREFIX)) {
                continue;
            }

            $name = substr($entry, strlen(BackupArchive::FILES_PREFIX));
            $temporary = $work.DIRECTORY_SEPARATOR.'file_'.bin2hex(random_bytes(4));
            BackupArchive::extract($zip, $entry, $temporary);

            $target = $directory.DIRECTORY_SEPARATOR.$name;

            if (is_link($target) || ! @rename($temporary, $target)) {
                if (! is_link($target) && @copy($temporary, $target)) {
                    @unlink($temporary);
                } else {
                    throw new BackupException(__('The uploaded files could not be restored.'));
                }
            }

            $restored[$name] = true;
        }

        foreach (scandir($directory) ?: [] as $name) {
            $path = $directory.DIRECTORY_SEPARATOR.$name;

            if (! isset($restored[$name]) && $name !== '.' && $name !== '..' && is_file($path) && ! str_starts_with($name, '.')) {
                @unlink($path);
            }
        }
    }
}
