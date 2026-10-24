<?php

declare(strict_types=1);

namespace kintai\Core\Services;

/**
 * Connaissance du schéma de version interne "X.Y.Z" (voir docs/releasing.md) :
 * X.Y (la "ligne" de release) est bumpé à la main dans config/app.php, tandis
 * que Z (l'itération alpha/beta au sein de la ligne, ou 0 pour le stable) est
 * calculé par .github/workflows/release.yml et ajouté au tag Git — le dépôt
 * ne contient donc toujours que "X.Y.0" dans config/app.php. Une fois une
 * mise à jour appliquée par l'auto-updater, GithubUpdateService réécrit ce
 * même champ avec le tag exact (vrai Z inclus) — voir UpdateService::setCurrentVersion().
 */
final class VersionScheme
{
    /** "X.Y" (la ligne de release), Z ignoré. */
    public static function lineOf(string $version): string
    {
        $parts = explode('.', $version, 3);

        return $parts[0] . '.' . ($parts[1] ?? '0');
    }

    public static function isNewer(string $latest, string $current): bool
    {
        return version_compare($latest, $current) > 0;
    }
}
