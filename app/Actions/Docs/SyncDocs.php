<?php

namespace App\Actions\Docs;

use App\Activity\ActivityLogger;
use App\Docs\DocsSync;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Synchronisation de la documentation lancée par l'Owner (bouton de la page Documentation).
 */
class SyncDocs
{
    public function __construct(private readonly DocsSync $sync, private readonly ActivityLogger $logger) {}

    /**
     * @return array{ok: bool, added: int, updated: int, unchanged: int, skipped: int, removed: int, errors: list<string>}
     *
     * @throws AuthorizationException
     */
    public function handle(User $actor): array
    {
        if (! Gate::forUser($actor)->allows('manage-settings')) {
            throw new AuthorizationException;
        }

        $result = $this->sync->sync();

        $this->logger->record('docs.synced', null, null, [
            'success' => $result['ok'],
            'added' => $result['added'],
            'updated' => $result['updated'],
            'removed' => $result['removed'],
        ], $actor);

        return $result;
    }
}
