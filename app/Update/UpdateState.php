<?php

namespace App\Update;

/**
 * État persistant d'une mise à jour (`state.json`). Écriture atomique (fichier temporaire, verrou exclusif, rename) : un
 * arrêt brutal laisse toujours soit l'ancien état complet, soit le nouveau. Un fichier illisible lève une UpdateException
 * plutôt que d'être ignoré : on ne devine pas l'état d'une mise à jour inachevée.
 *
 * Phases : idle, staged (archive extraite), swapped (remplacement des fichiers commencé ; `swapComplete` dit s'il est allé
 * à son terme), done, failed (retour arrière incomplet, voir `error`), rolled_back.
 */
final class UpdateState
{
    public const IDLE = 'idle';

    public const STAGED = 'staged';

    public const SWAPPED = 'swapped';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const ROLLED_BACK = 'rolled_back';

    private const PHASES = [self::IDLE, self::STAGED, self::SWAPPED, self::DONE, self::FAILED, self::ROLLED_BACK];

    public string $phase = self::IDLE;

    public ?string $from = null;

    public ?string $to = null;

    public ?string $backupId = null;

    /** @var array{enabled: bool, message: string}|null Maintenance d'avant la mise à jour, null si non mémorisée. */
    public ?array $maintenanceBefore = null;

    /** @var list<string> Fichiers remplacés (l'ancienne version est dans `previous/`). */
    public array $replaced = [];

    /** @var list<string> Fichiers créés par la mise à jour. */
    public array $created = [];

    /** @var list<string> Fichiers obsolètes supprimés (l'ancienne version est dans `previous/`). */
    public array $deleted = [];

    public bool $swapComplete = false;

    public bool $migrationsStarted = false;

    public bool $installedWritten = false;

    public bool $databaseRestored = false;

    public ?string $startedAt = null;

    /** @var list<string> */
    public array $error = [];

    public function __construct(private readonly string $file) {}

    /**
     * @throws UpdateException
     */
    public static function load(string $file): self
    {
        $state = new self($file);

        if (! file_exists($file)) {
            return $state;
        }

        $json = @file_get_contents($file);
        $data = is_string($json) ? json_decode($json, true) : null;
        $damaged = new UpdateException(__('The update state file is damaged. Delete :file to start over.', ['file' => $file]));

        if (! is_array($data) || ! in_array($data['phase'] ?? null, self::PHASES, true)) {
            throw $damaged;
        }

        foreach (['replaced', 'created', 'deleted', 'error'] as $key) {
            if (isset($data[$key]) && (! is_array($data[$key]) || array_filter($data[$key], 'is_string') !== $data[$key])) {
                throw $damaged;
            }
        }

        foreach (['from', 'to', 'backup_id', 'started_at'] as $key) {
            if (isset($data[$key]) && ! is_string($data[$key])) {
                throw $damaged;
            }
        }

        foreach (['swap_complete', 'migrations_started', 'installed_written', 'database_restored'] as $key) {
            if (isset($data[$key]) && ! is_bool($data[$key])) {
                throw $damaged;
            }
        }

        $before = $data['maintenance_before'] ?? null;

        if ($before !== null && (! is_array($before) || ! is_bool($before['enabled'] ?? null) || ! is_string($before['message'] ?? null))) {
            throw $damaged;
        }

        $state->phase = $data['phase'];
        $state->from = $data['from'] ?? null;
        $state->to = $data['to'] ?? null;
        $state->backupId = $data['backup_id'] ?? null;
        $state->maintenanceBefore = $before === null ? null : ['enabled' => $before['enabled'], 'message' => $before['message']];
        $state->replaced = array_values($data['replaced'] ?? []);
        $state->created = array_values($data['created'] ?? []);
        $state->deleted = array_values($data['deleted'] ?? []);
        $state->swapComplete = $data['swap_complete'] ?? false;
        $state->migrationsStarted = $data['migrations_started'] ?? false;
        $state->installedWritten = $data['installed_written'] ?? false;
        $state->databaseRestored = $data['database_restored'] ?? false;
        $state->startedAt = $data['started_at'] ?? null;
        $state->error = array_values($data['error'] ?? []);

        return $state;
    }

    /**
     * @throws UpdateException
     */
    public function save(): void
    {
        $json = json_encode([
            'phase' => $this->phase,
            'from' => $this->from,
            'to' => $this->to,
            'backup_id' => $this->backupId,
            'maintenance_before' => $this->maintenanceBefore,
            'replaced' => $this->replaced,
            'created' => $this->created,
            'deleted' => $this->deleted,
            'swap_complete' => $this->swapComplete,
            'migrations_started' => $this->migrationsStarted,
            'installed_written' => $this->installedWritten,
            'database_restored' => $this->databaseRestored,
            'started_at' => $this->startedAt,
            'error' => $this->error,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        $temporary = $this->file.'.tmp-'.bin2hex(random_bytes(4));

        if ($json === false || @file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)) {
            @unlink($temporary);

            throw new UpdateException(__('The update state could not be saved.'));
        }

        if (! @rename($temporary, $this->file)) {
            // Certains systèmes de fichiers (Windows) refusent d'écraser : repli.
            @unlink($this->file);

            if (! @rename($temporary, $this->file)) {
                @unlink($temporary);

                throw new UpdateException(__('The update state could not be saved.'));
            }
        }
    }

    /** Remet les champs à zéro (nouvelle mise à jour), sans écrire. */
    public function reset(): void
    {
        $this->phase = self::IDLE;
        $this->from = $this->to = $this->backupId = $this->startedAt = null;
        $this->maintenanceBefore = null;
        $this->replaced = $this->created = $this->deleted = $this->error = [];
        $this->swapComplete = $this->migrationsStarted = $this->installedWritten = $this->databaseRestored = false;
    }
}
