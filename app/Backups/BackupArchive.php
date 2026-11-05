<?php

namespace App\Backups;

use ZipArchive;

/**
 * Contrôle et lecture d'une archive de sauvegarde. Rien n'est jamais extrait « en bloc » (`extractTo`) : seules les
 * entrées attendues, au nom strictement validé, sont lues une à une, vers un fichier dont le nom est choisi par le
 * programme (protection contre le zip-slip, les liens symboliques et les archives piégées).
 */
final class BackupArchive
{
    /** Version du format du manifeste. */
    public const FORMAT = 1;

    public const MANIFEST = 'backup.json';

    public const FILES_PREFIX = 'files/avatars/';

    private const DATABASE_FILES = ['database.sqlite' => 'sqlite', 'database.sql' => 'mysql'];

    /** Nom d'un fichier téléversé : pas de séparateur, pas de point en tête, extension d'image. */
    private const FILE_PATTERN = '#^files/avatars/(?!\.)[A-Za-z0-9._-]{1,200}\.(?:jpe?g|png|gif|webp)$#i';

    /** Taille maximale du manifeste. */
    private const MANIFEST_MAX_BYTES = 65536;

    /**
     * Ouvre l'archive et vérifie son contenu : entrées attendues seulement, tailles bornées, manifeste cohérent.
     *
     * @return array{zip: ZipArchive, manifest: array<string, mixed>, files: array<string, int>} `files` : entrée => taille.
     *
     * @throws BackupException
     */
    public static function inspect(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new BackupException(__('This backup is not a readable archive.'));
        }

        try {
            return self::check($zip);
        } catch (BackupException $e) {
            $zip->close();

            throw $e;
        }
    }

    /**
     * Manifeste seul (liste des sauvegardes) : `null` si l'archive ou son manifeste est illisible.
     *
     * @return array<string, mixed>|null
     */
    public static function readManifest(string $path): ?array
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return null;
        }

        try {
            $json = $zip->getFromName(self::MANIFEST, self::MANIFEST_MAX_BYTES);
            $manifest = is_string($json) ? json_decode($json, true) : null;

            return is_array($manifest) ? $manifest : null;
        } finally {
            $zip->close();
        }
    }

    /**
     * Copie une entrée vers `$destination` (jamais plus que sa taille déclarée).
     *
     * @throws BackupException
     */
    public static function extract(ZipArchive $zip, string $entry, string $destination): void
    {
        $stat = $zip->statName($entry);
        $in = $stat === false ? false : $zip->getStream($entry);
        $out = $in === false ? false : fopen($destination, 'wb');

        if ($stat === false || $in === false || $out === false) {
            throw new BackupException(__('This backup is damaged.'));
        }

        $size = (int) $stat['size'];
        $copied = stream_copy_to_stream($in, $out, $size + 1);
        fclose($in);
        fclose($out);

        if ($copied !== $size) {
            throw new BackupException(__('This backup is damaged.'));
        }
    }

    /**
     * @return array{zip: ZipArchive, manifest: array<string, mixed>, files: array<string, int>}
     *
     * @throws BackupException
     */
    private static function check(ZipArchive $zip): array
    {
        $maxEntries = (int) config('kintai.backups.max_entries');
        $maxBytes = (int) config('kintai.backups.max_uncompressed_bytes');

        if ($zip->numFiles < 2 || $zip->numFiles > $maxEntries) {
            throw new BackupException(__('This backup does not have the expected content.'));
        }

        $files = [];
        $total = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);

            if ($stat === false) {
                throw new BackupException(__('This backup is damaged.'));
            }

            $name = $stat['name'];

            if ($name === 'files/' || $name === 'files/avatars/') {
                continue;
            }

            if (! self::isExpected($name) || isset($files[$name]) || self::isSymlink($zip, $i)) {
                throw new BackupException(__('This backup does not have the expected content.'));
            }

            $files[$name] = (int) $stat['size'];
            $total += (int) $stat['size'];

            if ($total > $maxBytes) {
                throw new BackupException(__('This backup is too large to be restored.'));
            }
        }

        $json = $zip->getFromName(self::MANIFEST, self::MANIFEST_MAX_BYTES);
        $manifest = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($manifest) || ($manifest['format'] ?? null) !== self::FORMAT) {
            throw new BackupException(__('The manifest of this backup is missing or invalid.'));
        }

        $driver = $manifest['driver'] ?? null;
        $database = $manifest['database_file'] ?? null;

        if (! is_string($driver) || ! is_string($database) || (self::DATABASE_FILES[$database] ?? null) !== $driver || ! isset($files[$database])) {
            throw new BackupException(__('The manifest of this backup is missing or invalid.'));
        }

        // Une seule base dans l'archive, celle que le manifeste annonce.
        if (count(array_intersect_key($files, self::DATABASE_FILES)) !== 1) {
            throw new BackupException(__('This backup does not have the expected content.'));
        }

        return ['zip' => $zip, 'manifest' => $manifest, 'files' => $files];
    }

    private static function isExpected(string $name): bool
    {
        return $name === self::MANIFEST
            || isset(self::DATABASE_FILES[$name])
            || preg_match(self::FILE_PATTERN, $name) === 1;
    }

    private static function isSymlink(ZipArchive $zip, int $index): bool
    {
        $opsys = 0;
        $attributes = 0;

        if (! $zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
            return false;
        }

        return $opsys === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000;
    }
}
