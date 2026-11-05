<?php

namespace App\Actions\Backups;

use App\Activity\ActivityLogger;
use App\Backups\BackupException;
use App\Backups\BackupInfo;
use App\Backups\BackupManager;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Sauvegarde manuelle (Owner seulement), avec une note facultative.
 */
class CreateBackup
{
    public function __construct(private readonly BackupManager $manager, private readonly ActivityLogger $logger) {}

    /**
     * @throws AuthorizationException
     * @throws BackupException
     */
    public function handle(User $actor, ?string $note = null): BackupInfo
    {
        if (! Gate::forUser($actor)->allows('manage-settings')) {
            throw new AuthorizationException;
        }

        $backup = $this->manager->create($note, BackupManager::TRIGGER_MANUAL);

        $this->logger->record('backup.created', null, null, ['backup' => $backup->id, 'size' => $backup->size, 'note' => $backup->note], $actor);

        return $backup;
    }
}
