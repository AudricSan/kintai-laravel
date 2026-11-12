<?php

namespace App\Backups;

use InvalidArgumentException;
use Kintai\Contracts\Backups\BackupFileRegistry;

/**
 * Registre en mémoire des dossiers de fichiers téléversés des bundles (voir l'interface du contrat). Valide strictement
 * clé et dossier : ce sont eux qui nomment les entrées d'une archive et les dossiers que la restauration réécrit.
 */
class DefaultBackupFileRegistry implements BackupFileRegistry
{
    /** Clé de la zone des avatars, réservée au Core. */
    public const AVATARS = 'avatars';

    public const KEY_PATTERN = '/^[a-z0-9][a-z0-9-]{0,39}$/';

    public const DIRECTORY_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,59}$/';

    /** @var array<string, string> clé => sous-dossier */
    private array $areas = [];

    public function register(string $key, string $directory): void
    {
        if ($key === self::AVATARS || preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException("Backup file area [{$key}] is invalid or reserved.");
        }

        if (preg_match(self::DIRECTORY_PATTERN, $directory) !== 1 || $directory === 'backups') {
            throw new InvalidArgumentException("Backup file directory [{$directory}] is invalid.");
        }

        if (isset($this->areas[$key]) && $this->areas[$key] !== $directory) {
            throw new InvalidArgumentException("Backup file area [{$key}] is already registered for another directory.");
        }

        if (in_array($directory, [...array_values($this->areas), 'avatars'], true) && ($this->areas[$key] ?? null) !== $directory) {
            throw new InvalidArgumentException("Backup file directory [{$directory}] is already used.");
        }

        $this->areas[$key] = $directory;
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->areas;
    }
}
