<?php

declare(strict_types=1);

namespace kintai\Core\Services;

use Closure;

/**
 * Surveille l'espace disque du serveur pour prévenir l'Owner, par un bandeau, que le stockage commence à être limité
 * (sauvegardes et fichiers envoyés s'accumulent dans storage/). Il informe seulement : rien n'est supprimé ni bloqué.
 *
 * Le seuil est atteint sous 1 Go libre ou sous 10 % du disque. La mesure est mise en cache quelques minutes : le bandeau
 * est évalué à chaque page, la taille des archives n'a pas besoin d'être recalculée à chaque fois.
 */
final class StorageUsageService
{
    /** Moins de 1 Go libre : stockage limité. */
    public const MIN_FREE_BYTES = 1073741824;

    /** Moins de 10 % du disque libre : stockage limité. */
    public const MIN_FREE_RATIO = 0.10;

    private const CACHE_TTL = 300;

    /**
     * @param string       $path      dossier dont on mesure le disque (défaut : storage/)
     * @param Closure|null $freeSpace  fn(string $path): int|false — surchargeable pour les tests
     * @param Closure|null $totalSpace fn(string $path): int|false — surchargeable pour les tests
     * @param string|null  $cacheFile  fichier de cache (défaut : storage/cache/storage-usage.json ; '' = sans cache)
     */
    public function __construct(
        private readonly string $path = '',
        private readonly ?Closure $freeSpace = null,
        private readonly ?Closure $totalSpace = null,
        private readonly ?string $cacheFile = null,
    ) {}

    /**
     * Données du bandeau si le stockage est limité, sinon null (assez d'espace, ou mesure impossible).
     *
     * @return array{free: string, total: string, percent: int, backups: string}|null
     */
    public function lowStorage(): ?array
    {
        $usage = $this->measure();
        if ($usage === null || !self::isLow($usage['free'], $usage['total'])) {
            return null;
        }

        return [
            'free'    => self::formatBytes($usage['free']),
            'total'   => self::formatBytes($usage['total']),
            'percent' => (int) round($usage['free'] / $usage['total'] * 100),
            'backups' => self::formatBytes($usage['backups']),
        ];
    }

    public static function isLow(int $freeBytes, int $totalBytes): bool
    {
        if ($totalBytes <= 0) {
            return false;
        }

        return $freeBytes < self::MIN_FREE_BYTES || $freeBytes / $totalBytes < self::MIN_FREE_RATIO;
    }

    public static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) max(0, $bytes);
        $i     = 0;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return ($i === 0 ? (string) (int) $value : number_format($value, 1, '.', '')) . ' ' . $units[$i];
    }

    /**
     * Mesure (ou relit le cache) : octets libres, octets totaux, octets occupés par les archives de sauvegarde.
     *
     * @return array{free: int, total: int, backups: int}|null
     */
    private function measure(): ?array
    {
        $cache = $this->cacheFile();
        if ($cache !== '' && is_file($cache) && time() - (int) @filemtime($cache) < self::CACHE_TTL) {
            $data = json_decode((string) @file_get_contents($cache), true);
            if (is_array($data) && isset($data['free'], $data['total'], $data['backups'])) {
                return ['free' => (int) $data['free'], 'total' => (int) $data['total'], 'backups' => (int) $data['backups']];
            }
        }

        $path  = $this->path !== '' ? $this->path : storage_path();
        $free  = $this->freeSpace !== null ? ($this->freeSpace)($path) : @disk_free_space($path);
        $total = $this->totalSpace !== null ? ($this->totalSpace)($path) : @disk_total_space($path);
        if ($free === false || $total === false || $total <= 0) {
            return null;
        }

        $usage = ['free' => (int) $free, 'total' => (int) $total, 'backups' => $this->backupsSize($path)];

        if ($cache !== '') {
            @mkdir(dirname($cache), 0755, true);
            @file_put_contents($cache, json_encode($usage));
        }

        return $usage;
    }

    /** Taille cumulée des archives de storage/backups (sauvegardes et instantanés de code d'avant mise à jour). */
    private function backupsSize(string $storagePath): int
    {
        $size = 0;
        foreach (glob(rtrim($storagePath, '/\\') . '/backups/*.zip') ?: [] as $file) {
            $size += (int) @filesize($file);
        }

        return $size;
    }

    private function cacheFile(): string
    {
        if ($this->cacheFile !== null) {
            return $this->cacheFile;
        }

        return storage_path('cache/storage-usage.json');
    }
}
