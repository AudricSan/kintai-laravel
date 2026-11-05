<?php

namespace Tests\Concerns;

use App\Backups\BackupManager;
use Illuminate\Support\Facades\Storage;
use PDO;
use ZipArchive;

/**
 * Dossier de sauvegardes jetable et disque `local` factice (avatars) pour les tests de sauvegarde.
 */
trait UsesBackupDirectory
{
    protected string $backupDir;

    protected function setUpBackupDirectory(): void
    {
        $this->backupDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'kintai-backups-'.bin2hex(random_bytes(4));
        config(['kintai.backups.path' => $this->backupDir]);
        Storage::fake('local');

        $this->beforeApplicationDestroyed(fn () => BackupManager::removeDirectory($this->backupDir));
    }

    protected function backups(): BackupManager
    {
        return $this->app->make(BackupManager::class);
    }

    /**
     * Archive fabriquée à la main (contenu et manifeste au choix) dans le dossier des sauvegardes ; renvoie son identifiant.
     * Sans `$entries['database.sqlite']`, la base courante est exportée.
     *
     * @param  array<string, string>  $entries  nom d'entrée => contenu
     * @param  array<string, mixed>|null  $manifest  null = pas de manifeste ; sinon fusionné avec le manifeste par défaut
     */
    protected function forgeArchive(array $entries = [], ?array $manifest = [], ?callable $tamperDatabase = null): string
    {
        $directory = $this->backups()->directory();
        $id = 'backup_20260101_000000_'.bin2hex(random_bytes(4)).'.zip';

        if (! isset($entries['database.sqlite']) && ! isset($entries['database.sql'])) {
            $database = $directory.DIRECTORY_SEPARATOR.'forge.sqlite';
            $this->backups()->dumper()->dump($database);

            if ($tamperDatabase !== null) {
                $tamperDatabase(new PDO('sqlite:'.$database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]));
            }

            $entries['database.sqlite'] = (string) file_get_contents($database);
            unlink($database);
        }

        if ($manifest !== null) {
            $entries['backup.json'] = (string) json_encode(array_merge([
                'format' => 1,
                'created_at' => '2026-01-01T00:00:00.000000Z',
                'note' => 'forged',
                'trigger' => 'manual',
                'driver' => 'sqlite',
                'database_file' => 'database.sqlite',
                'app_version' => '0.0.1',
                'last_migration' => '',
                'files' => 0,
                'size' => 0,
            ], $manifest));
        }

        $zip = new ZipArchive;
        $zip->open($directory.DIRECTORY_SEPARATOR.$id, ZipArchive::CREATE);

        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return $id;
    }

    /**
     * @return list<string> Noms des entrées d'une archive.
     */
    protected function archiveEntries(string $id): array
    {
        $zip = new ZipArchive;
        $zip->open($this->backupDir.DIRECTORY_SEPARATOR.$id);
        $names = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }

        $zip->close();
        sort($names);

        return $names;
    }
}
