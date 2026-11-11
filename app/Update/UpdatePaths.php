<?php

namespace App\Update;

/**
 * Emplacements d'une mise à jour : racine de l'application et dossier de travail `storage/app/updates/`
 * (`state.json`, `work/{version}/`, `previous/{version}/`, `installed-files.json`, `.lock`). Tout est reçu par le
 * constructeur pour pouvoir tester le moteur dans un faux dossier d'application.
 *
 * Gère aussi la liste des chemins PROTÉGÉS, que la mise à jour ne doit jamais écrire ni supprimer.
 */
final class UpdatePaths
{
    private readonly string $base;

    /**
     * @throws UpdateException
     */
    public function __construct(?string $basePath = null)
    {
        $real = realpath($basePath ?? base_path());

        if ($real === false || ! is_dir($real)) {
            throw new UpdateException(__('The application folder could not be found.'));
        }

        $this->base = rtrim($real, '/\\');
    }

    /** Racine de l'application (chemin réel, sans séparateur final). */
    public function base(): string
    {
        return $this->base;
    }

    /** Chemin absolu d'un chemin relatif à la racine (séparateurs `/`). */
    public function target(string $relative): string
    {
        return $this->base.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    public function directory(): string
    {
        return $this->target('storage/app/updates');
    }

    public function state(): string
    {
        return $this->directory().DIRECTORY_SEPARATOR.'state.json';
    }

    public function lock(): string
    {
        return $this->directory().DIRECTORY_SEPARATOR.'.lock';
    }

    public function installed(): string
    {
        return $this->directory().DIRECTORY_SEPARATOR.'installed-files.json';
    }

    /** Copie de `installed-files.json` d'avant la mise à jour (pour le retour arrière). */
    public function installedBackup(): string
    {
        return $this->directory().DIRECTORY_SEPARATOR.'installed-files.previous.json';
    }

    public function work(string $version): string
    {
        return $this->directory().DIRECTORY_SEPARATOR.'work'.DIRECTORY_SEPARATOR.$version;
    }

    public function previous(string $version): string
    {
        return $this->directory().DIRECTORY_SEPARATOR.'previous'.DIRECTORY_SEPARATOR.$version;
    }

    /**
     * Chemin protégé (relatif, séparateurs `/`) : `.env`, `storage/`, `database/*.sqlite`, `public/storage`,
     * `bootstrap/cache/` et `.git/`. Comparaison insensible à la casse (systèmes de fichiers Windows et macOS).
     */
    public function isProtected(string $relative): bool
    {
        $lower = strtolower(trim($relative, '/'));

        foreach (['.env', 'storage', 'public/storage', 'bootstrap/cache', '.git'] as $protected) {
            if ($lower === $protected || str_starts_with($lower, $protected.'/')) {
                return true;
            }
        }

        return preg_match('#^database/[^/]*\.sqlite$#', $lower) === 1;
    }
}
