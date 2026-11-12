<?php

namespace App\Backups;

use Carbon\CarbonImmutable;

/**
 * Une archive de sauvegarde listée par `BackupManager`. L'identifiant est le nom du fichier (motif strict), jamais un chemin.
 */
final class BackupInfo
{
    public function __construct(
        public readonly string $id,
        public readonly string $path,
        public readonly int $size,
        public readonly CarbonImmutable $createdAt,
        public readonly string $note,
        public readonly string $driver,
        public readonly string $version,
        public readonly string $trigger,
        public readonly bool $valid,
    ) {}
}
