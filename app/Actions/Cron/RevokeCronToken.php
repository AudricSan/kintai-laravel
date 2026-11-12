<?php

namespace App\Actions\Cron;

use App\Activity\ActivityLogger;
use App\Models\CronToken;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Révoque un jeton des tâches planifiées (Owner seulement) : l'appel HTTP qui l'utilise reçoit aussitôt un 404.
 */
class RevokeCronToken
{
    public function __construct(private readonly ActivityLogger $logger) {}

    /**
     * @throws AuthorizationException
     */
    public function handle(User $actor, int $tokenId): bool
    {
        if (! Gate::forUser($actor)->allows('manage-settings')) {
            throw new AuthorizationException;
        }

        $token = CronToken::query()->find($tokenId);

        if ($token === null) {
            return false;
        }

        $token->delete();
        $this->logger->recordFor('cron.token_revoked', $token->getMorphClass(), $token->id, null, ['name' => $token->name], $actor);

        return true;
    }
}
