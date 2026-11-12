<?php

namespace App\Update;

use Illuminate\Support\Facades\Cache;

/**
 * Recherche de la dernière release d'un canal, avec un cache de 15 minutes (par canal et par dépôt) pour ne pas solliciter
 * GitHub à chaque affichage de l'écran. `check()` contourne le cache et le rafraîchit ; `cached()` ne fait jamais d'appel réseau.
 */
class UpdateChecker
{
    public const CACHE_MINUTES = 15;

    /** Durée de conservation après un rafraîchissement planifié : le détecteur d'alerte lit ce cache sans réseau. */
    public const REFRESH_CACHE_MINUTES = 90;

    public function __construct(private readonly ReleaseClient $client) {}

    /**
     * Interroge GitHub (cache contourné), mémorise le résultat (15 minutes, ou `$cacheMinutes`) et le renvoie
     * (null : aucune release sur ce canal).
     *
     * @throws UpdateException
     */
    public function check(UpdateChannel $channel, ?int $cacheMinutes = null): ?Release
    {
        $release = $this->client->latest($channel);

        Cache::put($this->key($channel), ['release' => $release], now()->addMinutes($cacheMinutes ?? self::CACHE_MINUTES));

        return $release;
    }

    /**
     * Résultat de la dernière recherche encore en cache.
     *
     * @return array{release: ?Release}|null null : rien en cache
     */
    public function cached(UpdateChannel $channel): ?array
    {
        $value = Cache::get($this->key($channel));

        if (! is_array($value) || ! array_key_exists('release', $value) || ($value['release'] !== null && ! $value['release'] instanceof Release)) {
            return null;
        }

        return ['release' => $value['release']];
    }

    /** Cette release est-elle plus récente que la version installée ? */
    public function isNewer(?Release $release): bool
    {
        $current = Version::tryParse((string) config('kintai.version'));

        return $release !== null && ($current === null || $release->version->isNewerThan($current));
    }

    private function key(UpdateChannel $channel): string
    {
        return 'kintai.update.latest.'.$channel->value.'.'.sha1((string) config('kintai.update.repository'));
    }
}
