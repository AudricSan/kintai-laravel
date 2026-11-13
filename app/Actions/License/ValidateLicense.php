<?php

namespace App\Actions\License;

use App\Activity\ActivityLogger;
use App\License\LicenseClient;
use App\License\LicenseResult;
use App\License\LicenseState;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Valide la licence en ligne et met à jour l'état local. Appelée par l'Owner (écran) ou, sans acteur, par la commande
 * planifiée `kintai:license:validate`. Sans clé réglée : aucun appel. Un serveur injoignable ne retire jamais de droits.
 */
class ValidateLicense
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
    public function handle(?User $actor = null): ?LicenseResult
    {
        if ($actor !== null && ! Gate::forUser($actor)->allows('manage-settings')) {
            throw new AuthorizationException;
        }

        if (! $this->state->hasKey()) {
            return null;
        }

        $before = $this->state->state();
        $result = $this->state->apply($this->client->validate($this->state->licenseKey(), $this->state->instanceId(), $this->state->instanceLabel()));

        if ($result->isRefused() && $this->state->state() !== $before) {
            $this->logger->record('license.refused', null, null, ['error' => $result->error, 'during' => 'validation'], $actor);
        } elseif ($actor !== null) {
            $this->logger->record('license.validated', null, null, ['result' => $result->kind], $actor);
        }

        return $result;
    }
}
