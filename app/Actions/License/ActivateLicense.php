<?php

namespace App\Actions\License;

use App\Activity\ActivityLogger;
use App\License\LicenseClient;
use App\License\LicenseResult;
use App\License\LicenseState;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Active la licence de cette instance auprès du LicenseManager (Owner seulement). La clé n'est retenue (chiffrée) que si le
 * serveur l'accepte et que son jeton se vérifie ; elle n'est jamais écrite dans le journal.
 */
class ActivateLicense
{
    public const KEY_MAX = 200;

    public function __construct(
        private readonly LicenseClient $client,
        private readonly LicenseState $state,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, string $licenseKey): LicenseResult
    {
        if (! Gate::forUser($actor)->allows('manage-settings')) {
            throw new AuthorizationException;
        }

        $licenseKey = trim($licenseKey);

        if ($licenseKey === '' || mb_strlen($licenseKey) > self::KEY_MAX || preg_match('/[\x00-\x20]/', $licenseKey) === 1) {
            throw ValidationException::withMessages(['licenseKey' => __('Enter a valid license key.')]);
        }

        $result = $this->client->activate($licenseKey, $this->state->instanceId(), $this->state->instanceLabel());
        $result = $this->state->apply($result, $licenseKey);

        if ($result->isValid()) {
            $this->logger->record('license.activated', null, null, ['status' => $result->status, 'type' => $result->type], $actor);
        } elseif ($result->isRefused()) {
            $this->logger->record('license.refused', null, null, ['error' => $result->error, 'during' => 'activation'], $actor);
        }

        return $result;
    }
}
