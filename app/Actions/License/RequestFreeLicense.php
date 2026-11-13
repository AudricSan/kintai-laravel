<?php

namespace App\Actions\License;

use App\Activity\ActivityLogger;
use App\License\LicenseClient;
use App\License\LicenseResult;
use App\License\LicenseState;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Demande une licence gratuite au LicenseManager (Owner seulement) : le serveur envoie un e-mail de confirmation, puis la
 * clé de licence, que l'Owner colle dans le champ d'activation. Rien n'est retenu ici ; le journal ne garde que le domaine
 * de l'adresse, jamais l'adresse entière.
 */
class RequestFreeLicense
{
    public function __construct(
        private readonly LicenseClient $client,
        private readonly LicenseState $state,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, string $email): LicenseResult
    {
        if (! Gate::forUser($actor)->allows('manage-settings')) {
            throw new AuthorizationException;
        }

        $email = mb_strtolower(trim($email));

        Validator::make(['email' => $email], ['email' => ['required', 'email:rfc', 'max:254']], attributes: ['email' => __('Email')])->validate();

        $result = $this->client->register($email, $this->state->instanceId(), $this->state->instanceLabel());

        if ($result->isAccepted()) {
            $this->logger->record('license.register', null, null, ['email_domain' => substr($email, (int) strrpos($email, '@') + 1)], $actor);
        }

        return $result;
    }
}
