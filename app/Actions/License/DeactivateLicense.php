<?php

namespace App\Actions\License;

use App\Activity\ActivityLogger;
use App\License\LicenseClient;
use App\License\LicenseResult;
use App\License\LicenseState;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Désactive la licence de cette instance : libère le siège côté serveur puis oublie la clé et le jeton (Owner seulement).
 * Si le serveur est injoignable, rien n'est oublié (le siège resterait pris) : l'Owner peut réessayer.
 */
class DeactivateLicense
{
    public function __construct(
        private readonly LicenseClient $client,
        private readonly LicenseState $state,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * @return LicenseResult|null Null quand aucune clé n'est réglée.
     *
     * @throws AuthorizationException
     */
    public function handle(User $actor): ?LicenseResult
    {
        if (! Gate::forUser($actor)->allows('manage-settings')) {
            throw new AuthorizationException;
        }

        $result = $this->release();

        if ($result?->isAnswer()) {
            $this->logger->record('license.deactivated', null, null, [], $actor);
        }

        return $result;
    }

    /**
     * Libère le siège sans contrôle d'accès (réinitialisation d'usine : meilleur effort, jamais d'exception).
     */
    public function release(): ?LicenseResult
    {
        try {
            if (! $this->state->hasKey()) {
                return null;
            }

            $result = $this->client->deactivate($this->state->licenseKey(), $this->state->instanceId());

            if ($result->isAnswer()) {
                $this->state->forget();
            }

            return $result;
        } catch (Throwable $e) {
            report($e);

            return LicenseResult::unreachable();
        }
    }
}
