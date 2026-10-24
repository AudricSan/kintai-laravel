<?php

declare(strict_types=1);

namespace kintai\Core\Security;

/**
 * Compteur de tentatives sur fenêtre glissante, stocké en fichiers (un par clé) sous
 * storage/logs/rate-limits — sans dépendre d'une base ni d'APC/Redis, comme le reste de l'app.
 *
 * Chaque opération lecture-modification-écriture se fait sous verrou exclusif (flock) : l'ancien
 * compteur lisait sans verrou puis écrivait, si bien que des requêtes concurrentes pouvaient se
 * marcher dessus et sous-compter.
 */
final class AttemptCounter
{
    /** Au plus 1 fichier sur 100 déclenche un ménage des compteurs périmés depuis plus d'un jour. */
    private const PRUNE_ONE_IN = 100;
    private const STALE_AFTER = 86400;

    private readonly string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = rtrim($dir ?? dirname(__DIR__, 3) . '/storage/logs/rate-limits', '/\\');
    }

    /** Nombre de tentatives enregistrées pour $key dans les $window dernières secondes. */
    public function count(string $key, int $window): int
    {
        return count($this->recent($this->read($key), $window));
    }

    /** Enregistre une tentative maintenant et renvoie le nouveau total dans la fenêtre. */
    public function hit(string $key, int $window): int
    {
        $total = 0;
        $this->withLock($key, function (array $stamps) use ($window, &$total): array {
            $stamps = $this->recent($stamps, $window);
            $stamps[] = time();
            $total = count($stamps);
            return $stamps;
        });
        $this->maybePrune();
        return $total;
    }

    /** Efface le compteur de $key (ex. après une connexion réussie). */
    public function clear(string $key): void
    {
        $file = $this->file($key);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * Secondes à attendre avant que $key repasse sous $max tentatives : 0 si elle n'est pas
     * bloquée. Sert de valeur à l'en-tête Retry-After.
     */
    public function retryAfter(string $key, int $max, int $window): int
    {
        $stamps = $this->recent($this->read($key), $window);
        if (count($stamps) < $max) {
            return 0;
        }
        sort($stamps);
        // La tentative qui doit sortir de la fenêtre pour repasser sous le seuil.
        $oldestThatMatters = $stamps[count($stamps) - $max];
        return max(1, $oldestThatMatters + $window - time());
    }

    /** @param int[] $stamps @return int[] */
    private function recent(array $stamps, int $window): array
    {
        $limit = time() - $window;
        return array_values(array_filter($stamps, static fn($t) => is_int($t) && $t > $limit));
    }

    private function file(string $key): string
    {
        return $this->dir . '/' . hash('sha256', $key) . '.lock';
    }

    /** @return int[] */
    private function read(string $key): array
    {
        $file = $this->file($key);
        if (!is_file($file)) {
            return [];
        }
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return [];
        }
        try {
            flock($handle, LOCK_SH);
            $decoded = json_decode((string) stream_get_contents($handle), true);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        return is_array($decoded) ? $decoded : [];
    }

    /** @param callable(array): array $update */
    private function withLock(string $key, callable $update): void
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
        $handle = @fopen($this->file($key), 'c+');
        if ($handle === false) {
            // Stockage indisponible : on ne bloque pas le site, mais rien n'est compté.
            $update([]);
            return;
        }
        try {
            flock($handle, LOCK_EX);
            $decoded = json_decode((string) stream_get_contents($handle), true);
            $stamps = $update(is_array($decoded) ? $decoded : []);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($stamps));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function maybePrune(): void
    {
        if (random_int(1, self::PRUNE_ONE_IN) !== 1 || !is_dir($this->dir)) {
            return;
        }
        $limit = time() - self::STALE_AFTER;
        foreach (glob($this->dir . '/*.lock') ?: [] as $file) {
            if (@filemtime($file) < $limit) {
                @unlink($file);
            }
        }
    }
}
