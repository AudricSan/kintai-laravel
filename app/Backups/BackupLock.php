<?php

namespace App\Backups;

/**
 * Une seule opération lourde à la fois (sauvegarde, restauration, réinitialisation), même entre deux requêtes ou entre
 * le navigateur et le planificateur : verrou de fichier dans le dossier des sauvegardes (sans cache ni base, que la
 * restauration remplace). Réentrant dans le même processus : la restauration crée sa sauvegarde de sécurité sous son propre verrou.
 */
final class BackupLock
{
    private static int $depth = 0;

    /** @var resource|null */
    private static $handle = null;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     *
     * @throws BackupException si une autre opération est en cours
     */
    public static function run(string $directory, callable $callback): mixed
    {
        if (self::$depth === 0) {
            $handle = fopen($directory.DIRECTORY_SEPARATOR.'.lock', 'c');

            if ($handle === false || ! flock($handle, LOCK_EX | LOCK_NB)) {
                if (is_resource($handle)) {
                    fclose($handle);
                }

                throw new BackupException(__('Another backup operation is already running. Try again in a few minutes.'));
            }

            self::$handle = $handle;
        }

        self::$depth++;

        try {
            return $callback();
        } finally {
            self::$depth--;

            if (self::$depth === 0 && is_resource(self::$handle)) {
                flock(self::$handle, LOCK_UN);
                fclose(self::$handle);
                self::$handle = null;
            }
        }
    }
}
