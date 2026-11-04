<?php

declare(strict_types=1);

namespace App\Install;

/**
 * Verrou d'une installation en cours (audit SEC-01) : deux POST simultanés ne doivent pas écrire en même temps la base
 * et le .env, et aucune installation ne doit démarrer sur une instance que l'autre requête vient d'installer. Le verrou
 * (flock sur storage/install.running) est libéré à la fin du processus ou par release().
 */
final class InstallLock
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(private readonly string $basePath)
    {
    }

    /** Prend le verrou : faux si une autre installation tourne, ou si l'instance est déjà installée. */
    public function acquire(): bool
    {
        $handle = @fopen($this->basePath . '/storage/install.running', 'c');
        if ($handle === false) {
            // Dossier non inscriptible : l'installateur le signale déjà dans les prérequis.
            return !file_exists($this->basePath . '/storage/installed.lock');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }
        if (file_exists($this->basePath . '/storage/installed.lock')) {
            flock($handle, LOCK_UN);
            fclose($handle);

            return false;
        }
        $this->handle = $handle;

        return true;
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }
}
