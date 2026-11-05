<?php

namespace App\Backups;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;
use ZipArchive;

/**
 * Archives de sauvegarde : création, liste, suppression, rotation. Tout se passe en PHP pur (ZipArchive + PDO), donc sur
 * un hébergement mutualisé sans terminal ni `mysqldump`.
 *
 * Archive `backup_{Ymd_His}_{aléa}.zip` dans `storage/app/private/backups` (hors du dossier public) : la base
 * (`database.sqlite` ou `database.sql`), les fichiers téléversés (`files/avatars/…`) et `backup.json` (le manifeste).
 * Écrite dans un fichier temporaire puis renommée : une archive n'apparaît dans la liste que complète.
 *
 * Les identifiants sont des noms de fichier au motif strict ; on ne reçoit jamais un chemin : `find()` ne renvoie que ce
 * que le dossier contient réellement, et tout chemin est revérifié avant d'être lu ou supprimé.
 */
class BackupManager
{
    public const ID_PATTERN = '/^backup_\d{8}_\d{6}_[0-9a-f]{8}\.zip$/';

    /** Même motif, sans ancres, pour les routes. */
    public const ROUTE_PATTERN = 'backup_[0-9]{8}_[0-9]{6}_[0-9a-f]{8}\.zip';

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_SCHEDULED = 'scheduled';

    public const TRIGGER_SAFETY = 'safety';

    public const TRIGGER_RESET = 'reset';

    public const NOTE_MAX = 200;

    public function __construct(private readonly ?DatabaseDumper $dumper = null) {}

    /**
     * Dumper du pilote en cours.
     *
     * @throws BackupException pilote non pris en charge
     */
    public function dumper(): DatabaseDumper
    {
        if ($this->dumper !== null) {
            return $this->dumper;
        }

        $connection = DB::connection();

        return match ($connection->getDriverName()) {
            'sqlite' => new SqliteDumper($connection),
            'mysql', 'mariadb' => new MysqlDumper($connection),
            default => throw new BackupException(__('Backups are not available for this database type (:driver). SQLite, MySQL and MariaDB are supported.', ['driver' => $connection->getDriverName()])),
        };
    }

    /** Dossier des archives (créé au besoin, fermé à l'accès web par précaution). */
    public function directory(): string
    {
        $directory = (string) config('kintai.backups.path');

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new BackupException(__('The backups folder cannot be created.'));
        }

        $guard = $directory.DIRECTORY_SEPARATOR.'.htaccess';
        if (! file_exists($guard)) {
            @file_put_contents($guard, "Require all denied\nDeny from all\n");
        }

        return $directory;
    }

    /**
     * Crée une sauvegarde complète.
     *
     * @throws BackupException
     */
    public function create(?string $note = null, string $trigger = self::TRIGGER_MANUAL): BackupInfo
    {
        $directory = $this->directory();
        $dumper = $this->dumper();

        return BackupLock::run($directory, function () use ($directory, $dumper, $note, $trigger): BackupInfo {
            @set_time_limit(0);

            $now = CarbonImmutable::now('UTC');
            $id = 'backup_'.$now->format('Ymd_His').'_'.bin2hex(random_bytes(4)).'.zip';
            $work = $directory.DIRECTORY_SEPARATOR.'.work_'.bin2hex(random_bytes(6));
            $partial = $directory.DIRECTORY_SEPARATOR.'.tmp_'.bin2hex(random_bytes(6)).'.part';

            try {
                if (! mkdir($work, 0775)) {
                    throw new BackupException(__('The backups folder cannot be written.'));
                }

                $database = $work.DIRECTORY_SEPARATOR.$dumper->fileName();
                $dumper->dump($database);

                $this->write($partial, $database, $dumper, $note, $trigger, $now);

                if (! rename($partial, $directory.DIRECTORY_SEPARATOR.$id)) {
                    throw new BackupException(__('The backup could not be saved.'));
                }
            } catch (BackupException $e) {
                throw $e;
            } catch (Throwable $e) {
                throw new BackupException(__('The backup could not be created.'), 0, $e);
            } finally {
                if (file_exists($partial)) {
                    @unlink($partial);
                }

                self::removeDirectory($work);
            }

            return $this->find($id) ?? throw new BackupException(__('The backup could not be created.'));
        });
    }

    /**
     * @return list<BackupInfo> Plus récentes d'abord.
     */
    public function all(): array
    {
        $directory = $this->directory();
        $backups = [];

        foreach (scandir($directory) ?: [] as $name) {
            if (preg_match(self::ID_PATTERN, $name) !== 1) {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$name;

            // Un lien symbolique n'est jamais une sauvegarde de Kintai.
            if (is_link($path) || ! is_file($path)) {
                continue;
            }

            $backups[] = $this->describe($name, $path);
        }

        usort($backups, fn (BackupInfo $a, BackupInfo $b): int => [$b->createdAt->getTimestampMs(), $b->id] <=> [$a->createdAt->getTimestampMs(), $a->id]);

        return $backups;
    }

    /** La sauvegarde d'identifiant `$id`, si elle figure dans la liste (jamais un chemin libre). */
    public function find(string $id): ?BackupInfo
    {
        foreach ($this->all() as $backup) {
            if ($backup->id === $id) {
                return $backup;
            }
        }

        return null;
    }

    public function delete(BackupInfo $backup): void
    {
        $path = $this->managedPath($backup);

        if (! @unlink($path)) {
            throw new BackupException(__('The backup could not be deleted.'));
        }
    }

    /**
     * @return int Nombre d'archives supprimées.
     */
    public function deleteAll(): int
    {
        $deleted = 0;

        foreach ($this->all() as $backup) {
            $this->delete($backup);
            $deleted++;
        }

        return $deleted;
    }

    /**
     * Rotation : ne garde que les `$maxKeep` plus récentes (0 = illimité).
     *
     * @return int Nombre d'archives supprimées.
     */
    public function prune(int $maxKeep): int
    {
        if ($maxKeep <= 0) {
            return 0;
        }

        $deleted = 0;

        foreach (array_slice($this->all(), $maxKeep) as $backup) {
            try {
                $this->delete($backup);
                $deleted++;
            } catch (BackupException $e) {
                report($e);
            }
        }

        return $deleted;
    }

    /** Dossier des fichiers téléversés sauvegardés (disque privé `local`). */
    public function avatarsDirectory(): string
    {
        return Storage::disk('local')->path('avatars');
    }

    /**
     * Chemin d'une sauvegarde, après avoir vérifié qu'il est bien dans le dossier des sauvegardes.
     *
     * @throws BackupException
     */
    public function managedPath(BackupInfo $backup): string
    {
        $directory = realpath($this->directory());
        $path = $directory === false ? false : realpath($backup->path);

        if (
            $directory === false || $path === false
            || preg_match(self::ID_PATTERN, $backup->id) !== 1
            || is_link($backup->path)
            || $path !== $directory.DIRECTORY_SEPARATOR.$backup->id
        ) {
            throw new BackupException(__('This backup does not exist.'));
        }

        return $path;
    }

    private function describe(string $id, string $path): BackupInfo
    {
        $manifest = BackupArchive::readManifest($path);
        $valid = $manifest !== null && ($manifest['format'] ?? null) === BackupArchive::FORMAT;

        try {
            $createdAt = CarbonImmutable::parse((string) ($manifest['created_at'] ?? ''));
        } catch (Throwable) {
            $createdAt = CarbonImmutable::createFromTimestamp((int) filemtime($path));
            $valid = false;
        }

        return new BackupInfo(
            id: $id,
            path: $path,
            size: (int) filesize($path),
            createdAt: $createdAt,
            note: is_string($manifest['note'] ?? null) ? $manifest['note'] : '',
            driver: is_string($manifest['driver'] ?? null) ? $manifest['driver'] : '',
            version: is_string($manifest['app_version'] ?? null) ? $manifest['app_version'] : '',
            trigger: is_string($manifest['trigger'] ?? null) ? $manifest['trigger'] : self::TRIGGER_MANUAL,
            valid: $valid,
        );
    }

    /**
     * @throws BackupException
     */
    private function write(string $archive, string $database, DatabaseDumper $dumper, ?string $note, string $trigger, CarbonImmutable $now): void
    {
        $zip = new ZipArchive;

        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new BackupException(__('The backups folder cannot be written.'));
        }

        $files = [];
        $avatars = $this->avatarsDirectory();

        if (is_dir($avatars) && ! is_link($avatars)) {
            foreach (scandir($avatars) ?: [] as $name) {
                $path = $avatars.DIRECTORY_SEPARATOR.$name;

                if (preg_match('/^(?!\.)[A-Za-z0-9._-]{1,200}\.(?:jpe?g|png|gif|webp)$/i', $name) === 1 && is_file($path) && ! is_link($path)) {
                    $files[$name] = $path;
                }
            }
        }

        $bytes = (int) filesize($database);

        foreach ($files as $name => $path) {
            $bytes += (int) filesize($path);
        }

        $manifest = [
            'format' => BackupArchive::FORMAT,
            'created_at' => $now->format('Y-m-d\TH:i:s.u\Z'),
            'note' => mb_substr(trim((string) $note), 0, self::NOTE_MAX),
            'trigger' => $trigger,
            'driver' => $dumper->driver(),
            'database_file' => $dumper->fileName(),
            'app_version' => (string) config('kintai.version'),
            'last_migration' => $this->lastMigration(),
            'files' => count($files),
            'size' => $bytes,
        ];

        $zip->addFile($database, $dumper->fileName());
        $zip->addFromString(BackupArchive::MANIFEST, (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        foreach ($files as $name => $path) {
            $zip->addFile($path, BackupArchive::FILES_PREFIX.$name);
        }

        if (! $zip->close()) {
            throw new BackupException(__('The backup could not be saved.'));
        }
    }

    private function lastMigration(): string
    {
        try {
            return (string) $this->connection()->table('migrations')->orderByDesc('id')->value('migration');
        } catch (Throwable) {
            return '';
        }
    }

    private function connection(): Connection
    {
        return DB::connection();
    }

    public static function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$name;
            is_dir($path) && ! is_link($path) ? self::removeDirectory($path) : @unlink($path);
        }

        @rmdir($directory);
    }
}
