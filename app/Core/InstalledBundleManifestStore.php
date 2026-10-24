<?php

declare(strict_types=1);

namespace kintai\Core;

/**
 * Miroir filesystem (storage/bundles/installed.json) de la table
 * installed_bundles : slug -> version active + chemin. Ce fichier n'est PAS
 * la source de vérité métier (c'est la table, voir InstalledBundleRepositoryInterface)
 * mais un cache de déploiement régénérable à tout moment depuis elle, lu très
 * tôt au boot (BundleDiscoveryService, InstalledBundleAutoloader) avant toute
 * garantie de disponibilité de la base de données — même contrainte que le
 * discovery legacy actuel, qui ne dépend déjà d'aucune connexion DB. Précédent
 * direct : storage/app/update-files-manifest.json (GithubUpdateService).
 */
final class InstalledBundleManifestStore
{
    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? storage_path('bundles/installed.json');
    }

    /**
     * @return array<string, array{active_version: string}>
     */
    public function all(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($this->path), true);
        return is_array($data) ? $data : [];
    }

    public function setActiveVersion(string $slug, string $version): void
    {
        $data = $this->all();
        $data[$slug] = ['active_version' => $version];
        $this->write($data);
    }

    public function remove(string $slug): void
    {
        $data = $this->all();
        unset($data[$slug]);
        $this->write($data);
    }

    /** @param array<string, array{active_version: string}> $data */
    private function write(array $data): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(
            $this->path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }
}
