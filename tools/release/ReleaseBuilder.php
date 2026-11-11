<?php

declare(strict_types=1);

/**
 * Construit l'archive de release `kintai-X.Y.Z.zip` et sa somme `kintai-X.Y.Z.zip.sha256`, au format attendu par
 * `App\Update\ReleaseArchive` (manifeste `kintai-release.json` à la racine, aucun lien symbolique, aucun fichier propre
 * à l'instance : `.env`, `storage/`, base SQLite, cache de bootstrap).
 *
 * Utilisé par `build.php` (CI) ; sans dépendance au framework pour pouvoir tourner avant `php artisan`.
 */
final class ReleaseBuilder
{
    public const MANIFEST = 'kintai-release.json';

    /** Dossiers et fichiers de premier niveau jamais livrés. */
    private const EXCLUDED_TOP = [
        '.git', '.github', '.idea', '.vscode', '.impeccable', '.claude', '.env', 'node_modules', 'tests', 'storage',
        'docs', 'tools', 'dist',
        '.phpunit.result.cache', 'phpunit.xml', 'phpstan.neon', 'pint.json', '.editorconfig', '.gitattributes',
        '.gitignore', '.npmrc', 'package.json', 'package-lock.json', 'vite.config.js', 'CONTRIBUTING.md',
    ];

    public function __construct(private readonly string $root) {}

    /**
     * @return array{zip: string, checksum: string, files: int}
     */
    public function build(string $version, string $outputDirectory, ?string $minFromVersion = null): array
    {
        if (preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $version) !== 1) {
            throw new RuntimeException("Version invalide : {$version}");
        }

        if (! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0775, true) && ! is_dir($outputDirectory)) {
            throw new RuntimeException("Dossier de sortie impossible à créer : {$outputDirectory}");
        }

        $zipPath = rtrim($outputDirectory, '/\\').DIRECTORY_SEPARATOR."kintai-{$version}.zip";

        if (is_file($zipPath)) {
            unlink($zipPath);
        }

        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException("Archive impossible à créer : {$zipPath}");
        }

        $files = 0;

        foreach ($this->files() as $relative => $absolute) {
            if (! $zip->addFile($absolute, $relative)) {
                throw new RuntimeException("Fichier impossible à ajouter : {$relative}");
            }

            $files++;
        }

        $manifest = json_encode([
            'version' => $version,
            'min_php' => $this->minPhp(),
            'min_from_version' => $minFromVersion,
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        $zip->addFromString(self::MANIFEST, $manifest."\n");
        $files++;

        if (! $zip->close()) {
            throw new RuntimeException('Archive impossible à écrire.');
        }

        $hash = hash_file('sha256', $zipPath);

        if ($hash === false) {
            throw new RuntimeException('Somme SHA-256 impossible à calculer.');
        }

        $checksum = $zipPath.'.sha256';
        file_put_contents($checksum, $hash.'  '.basename($zipPath)."\n");

        return ['zip' => $zipPath, 'checksum' => $checksum, 'files' => $files];
    }

    /**
     * Fichiers à livrer, chemin relatif (séparateur `/`) => chemin absolu, triés.
     *
     * @return array<string, string>
     */
    public function files(): array
    {
        $root = rtrim(str_replace('\\', '/', (string) realpath($this->root)), '/');
        $found = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                function (SplFileInfo $file) use ($root): bool {
                    $relative = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($root)), '/');

                    if ($file->isLink()) {
                        throw new RuntimeException("Lien symbolique interdit dans l'archive : {$relative}. Installer les dépendances avec COMPOSER_MIRROR_PATH_REPOS=1.");
                    }

                    return ! $this->isExcluded($relative, $file->isDir());
                },
            ),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relative = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($root)), '/');
            $found[$relative] = $file->getPathname();
        }

        ksort($found);

        if (! isset($found['artisan'], $found['composer.json']) || ! isset($found['vendor/autoload.php'])) {
            throw new RuntimeException('Arborescence incomplète : artisan, composer.json et vendor/ sont requis (composer install --no-dev).');
        }

        return $found;
    }

    private function isExcluded(string $relative, bool $directory): bool
    {
        $lower = strtolower($relative);

        foreach (self::EXCLUDED_TOP as $excluded) {
            if ($lower === $excluded || str_starts_with($lower, $excluded.'/')) {
                return true;
            }
        }

        if (basename($lower) === '.git' || basename($lower) === '.ds_store' || basename($lower) === 'thumbs.db') {
            return true;
        }

        if ($directory) {
            return false;
        }

        return $lower === 'public/hot'
            || preg_match('#^bootstrap/cache/[^/]*\.php$#', $lower) === 1
            || preg_match('#^database/[^/]*\.sqlite#', $lower) === 1
            || preg_match('#^\.env(\.|$)#', $lower) === 1 && $lower !== '.env.example';
    }

    /** Version minimale de PHP, déduite de `composer.json` (`^8.3` donne `8.3`). */
    private function minPhp(): ?string
    {
        $composer = json_decode((string) file_get_contents($this->root.'/composer.json'), true);
        $constraint = is_array($composer) ? ($composer['require']['php'] ?? null) : null;

        if (is_string($constraint) && preg_match('/(\d+(?:\.\d+){0,2})/', $constraint, $match) === 1) {
            return $match[1];
        }

        return null;
    }
}
