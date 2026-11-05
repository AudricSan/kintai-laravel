<?php

namespace App\Actions\Backups;

use App\Activity\ActivityLogger;
use App\Backups\BackupException;
use App\Backups\BackupManager;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Suppression d'une sauvegarde, ou de toutes (Owner seulement). L'identifiant est cherché dans la liste réelle du dossier.
 */
class DeleteBackup
{
    public function __construct(private readonly BackupManager $manager, private readonly ActivityLogger $logger) {}

    /**
     * @throws AuthorizationException
     * @throws BackupException
     */
    public function handle(User $actor, string $id): void
    {
        $this->authorize($actor);

        $backup = $this->manager->find($id) ?? throw new BackupException(__('This backup does not exist.'));

        $this->manager->delete($backup);

        $this->logger->record('backup.deleted', null, null, ['backup' => $backup->id], $actor);
    }

    /**
     * @return int Nombre de sauvegardes supprimées.
     *
     * @throws AuthorizationException
     */
    public function all(User $actor): int
    {
        $this->authorize($actor);

        $deleted = $this->manager->deleteAll();

        $this->logger->record('backup.deleted_all', null, null, ['deleted' => $deleted], $actor);

        return $deleted;
    }

    private function authorize(User $actor): void
    {
        if (! Gate::forUser($actor)->allows('manage-settings')) {
            throw new AuthorizationException;
        }
    }
}
