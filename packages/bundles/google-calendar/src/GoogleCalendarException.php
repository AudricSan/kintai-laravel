<?php

namespace Kintai\Bundles\GoogleCalendar;

use RuntimeException;

/**
 * Échec d'un appel à Google (OAuth ou API Agenda), avec de quoi décider : réessayer, abandonner ou révoquer la liaison.
 * Le message ne contient jamais de jeton.
 */
class GoogleCalendarException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 0, public readonly string $reason = '')
    {
        parent::__construct($message);
    }

    /**
     * Le jeton de rafraîchissement n'est plus valable (accès retiré, mot de passe changé, projet en test depuis 7 jours)
     * ou les identifiants du projet Google sont refusés.
     */
    public function isAuthRevoked(): bool
    {
        return in_array($this->reason, ['invalid_grant', 'invalid_client', 'unauthorized_client'], true);
    }

    /**
     * La ressource n'existe plus côté Google (événement ou agenda supprimé).
     */
    public function isGone(): bool
    {
        return $this->httpStatus === 404 || $this->httpStatus === 410;
    }

    /**
     * Panne passagère : réseau, limite de débit, erreur serveur.
     */
    public function isRetryable(): bool
    {
        return $this->httpStatus === 0
            || $this->httpStatus === 429
            || $this->httpStatus >= 500
            || ($this->httpStatus === 403 && in_array($this->reason, ['rateLimitExceeded', 'userRateLimitExceeded'], true));
    }
}
