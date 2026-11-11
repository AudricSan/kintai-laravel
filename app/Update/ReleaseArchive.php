<?php

namespace App\Update;

use ZipArchive;

/**
 * Contrôle et extraction de l'archive d'une release. Chaque entrée est validée (zip-slip, liens symboliques, doublons, chemins
 * interdits, plafonds) avant tout ; l'extraction recopie ensuite les entrées une à une, bornées à leur taille déclarée.
 * Un unique dossier racine commun (`kintai-1.2.3/`) est toléré et retiré.
 */
final class ReleaseArchive
{
    public const MANIFEST = 'kintai-release.json';

    private const REQUIRED = [self::MANIFEST, 'artisan', 'vendor/autoload.php', 'composer.json'];

    private const MANIFEST_MAX_BYTES = 65536;

    /**
     * Valide l'archive sans rien extraire.
     *
     * @return array{version: Version, min_php: ?string, min_from_version: ?string, files: int, bytes: int}
     *
     * @throws UpdateException
     */
    public static function inspect(string $zipPath, ?Version $expected = null): array
    {
        $zip = self::open($zipPath);

        try {
            $scan = self::scan($zip);
            $manifest = self::manifest($zip, $scan['root']);

            if ($expected !== null && $manifest['version']->compare($expected) !== 0) {
                throw new UpdateException(__('The version announced by the archive (:found) is not the expected one (:expected).', ['found' => (string) $manifest['version'], 'expected' => (string) $expected]));
            }

            return ['version' => $manifest['version'], 'min_php' => $manifest['min_php'], 'min_from_version' => $manifest['min_from_version'], 'files' => $scan['files'], 'bytes' => $scan['bytes']];
        } finally {
            $zip->close();
        }
    }

    /**
     * Valide puis extrait l'archive dans `$directory` (créé si absent), sans le dossier racine éventuel.
     *
     * @throws UpdateException
     */
    public static function extractTo(string $zipPath, string $directory): void
    {
        $zip = self::open($zipPath);

        try {
            $scan = self::scan($zip);
            self::manifest($zip, $scan['root']);

            if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
                throw new UpdateException(__('The update folder could not be created.'));
            }

            $base = realpath($directory);

            if ($base === false) {
                throw new UpdateException(__('The update folder could not be created.'));
            }

            foreach ($scan['entries'] as $entry) {
                $target = $base.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $entry['path']);
                $folder = $entry['directory'] ? $target : dirname($target);

                if (! is_dir($folder) && ! mkdir($folder, 0775, true) && ! is_dir($folder)) {
                    throw new UpdateException(__('The update archive is damaged.'));
                }

                $real = realpath($folder);

                if ($real === false || ($real !== $base && ! str_starts_with($real, $base.DIRECTORY_SEPARATOR))) {
                    throw new UpdateException(__('The update archive contains an unsafe path.'));
                }

                if (! $entry['directory']) {
                    self::copyEntry($zip, $entry['name'], $target, $entry['size']);
                }
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * @throws UpdateException
     */
    private static function open(string $zipPath): ZipArchive
    {
        $zip = new ZipArchive;

        if (! is_file($zipPath) || $zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new UpdateException(__('The update archive is not readable.'));
        }

        return $zip;
    }

    /**
     * @return array{root: string, files: int, bytes: int, entries: list<array{name: string, path: string, directory: bool, size: int}>}
     *
     * @throws UpdateException
     */
    private static function scan(ZipArchive $zip): array
    {
        $maxEntries = (int) config('kintai.update.max_entries');
        $maxBytes = (int) config('kintai.update.max_uncompressed_bytes');

        if ($zip->numFiles === 0 || $zip->numFiles > $maxEntries) {
            throw new UpdateException(__('The update archive does not have the expected content.'));
        }

        $raw = [];
        $total = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);

            if ($stat === false) {
                throw new UpdateException(__('The update archive is damaged.'));
            }

            $name = $stat['name'];
            $directory = str_ends_with($name, '/');
            $path = $directory ? substr($name, 0, -1) : $name;

            if (! self::isSafePath($path) || self::isSymlink($zip, $i)) {
                throw new UpdateException(__('The update archive contains an unsafe path.'));
            }

            $size = $directory ? 0 : (int) $stat['size'];
            $total += $size;

            if ($total > $maxBytes) {
                throw new UpdateException(__('The update archive is too large once extracted.'));
            }

            $raw[] = ['name' => $name, 'path' => $path, 'directory' => $directory, 'size' => $size];
        }

        $root = self::root($raw);
        $entries = [];
        $seen = [];
        $files = 0;

        foreach ($raw as $entry) {
            if ($root !== '') {
                // L'entrée du dossier racine lui-même n'apporte rien.
                if ($entry['path'] === rtrim($root, '/')) {
                    continue;
                }

                $entry['path'] = substr($entry['path'], strlen($root));
            }

            $key = strtolower($entry['path']);

            if (isset($seen[$key])) {
                throw new UpdateException(__('The update archive contains duplicate entries.'));
            }

            $seen[$key] = true;

            if (self::isForbidden($entry['path'], $entry['directory'])) {
                throw new UpdateException(__('The update archive contains forbidden files (:path).', ['path' => $entry['path']]));
            }

            if (! $entry['directory']) {
                $files++;
            }

            $entries[] = $entry;
        }

        foreach (self::REQUIRED as $required) {
            if (! isset($seen[$required]) || self::directoryAt($entries, $required)) {
                throw new UpdateException(__('The update archive does not have the expected content (:path is missing).', ['path' => $required]));
            }
        }

        return ['root' => $root, 'files' => $files, 'bytes' => $total, 'entries' => $entries];
    }

    /**
     * Dossier racine unique à retirer (« kintai-1.2.3/ »), ou '' s'il n'y en a pas.
     *
     * @param  list<array{name: string, path: string, directory: bool, size: int}>  $raw
     */
    private static function root(array $raw): string
    {
        $first = null;

        foreach ($raw as $entry) {
            if ($entry['path'] === self::MANIFEST) {
                return '';
            }

            $segment = explode('/', $entry['path'], 2);

            // Un fichier à la racine de l'archive : pas de dossier commun.
            if (count($segment) === 1 && ! $entry['directory']) {
                return '';
            }

            if ($first !== null && $first !== $segment[0]) {
                return '';
            }

            $first = $segment[0];
        }

        return $first === null ? '' : $first.'/';
    }

    /**
     * @param  list<array{name: string, path: string, directory: bool, size: int}>  $entries
     */
    private static function directoryAt(array $entries, string $path): bool
    {
        foreach ($entries as $entry) {
            if ($entry['path'] === $path) {
                return $entry['directory'];
            }
        }

        return false;
    }

    private static function isSafePath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) === 1) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    private static function isForbidden(string $path, bool $directory): bool
    {
        $lower = strtolower($path);

        if ($lower === '.env' || $lower === '.git' || str_starts_with($lower, '.git/')) {
            return true;
        }

        if ($lower === 'storage' || str_starts_with($lower, 'storage/')) {
            return ! $directory && $lower !== 'storage/.gitkeep';
        }

        return ! $directory && (preg_match('#^database/[^/]*\.sqlite$#', $lower) === 1 || preg_match('#^bootstrap/cache/[^/]*\.php$#', $lower) === 1);
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

    /**
     * @return array{version: Version, min_php: ?string, min_from_version: ?string}
     *
     * @throws UpdateException
     */
    private static function manifest(ZipArchive $zip, string $root): array
    {
        $json = $zip->getFromName($root.self::MANIFEST, self::MANIFEST_MAX_BYTES);
        $data = is_string($json) ? json_decode($json, true) : null;
        $version = is_array($data) && is_string($data['version'] ?? null) ? Version::tryParse($data['version']) : null;
        $minPhp = is_array($data) ? ($data['min_php'] ?? null) : null;
        $minFrom = is_array($data) ? ($data['min_from_version'] ?? null) : null;

        if ($version === null || ($minPhp !== null && (! is_string($minPhp) || preg_match('/^\d+(?:\.\d+){0,2}$/', $minPhp) !== 1))
            || ($minFrom !== null && (! is_string($minFrom) || Version::tryParse($minFrom) === null))) {
            throw new UpdateException(__('The manifest of the update archive is missing or invalid.'));
        }

        return ['version' => $version, 'min_php' => $minPhp, 'min_from_version' => $minFrom];
    }

    /**
     * @throws UpdateException
     */
    private static function copyEntry(ZipArchive $zip, string $entry, string $destination, int $size): void
    {
        $in = $zip->getStream($entry);
        $out = $in === false ? false : fopen($destination, 'wb');

        if ($in === false || $out === false) {
            throw new UpdateException(__('The update archive is damaged.'));
        }

        $copied = stream_copy_to_stream($in, $out, $size + 1);
        fclose($in);
        fclose($out);

        if ($copied !== $size) {
            @unlink($destination);

            throw new UpdateException(__('The update archive is damaged.'));
        }
    }
}
