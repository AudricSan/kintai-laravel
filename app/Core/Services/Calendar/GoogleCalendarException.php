<?php

declare(strict_types=1);

namespace kintai\Core\Services\Calendar;

/** Échec d'un appel à Google (OAuth ou API Agenda), avec de quoi décider : réessayer, abandonner ou révoquer la liaison. */
final class GoogleCalendarException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 0, public readonly string $reason = '')
    {
        parent::__construct($message);
    }

    /** Le jeton de rafraîchissement n'est plus valable (accès retiré, mot de passe changé, projet en test depuis 7 jours). */
    public function isAuthRevoked(): bool
    {
        return $this->reason === 'invalid_grant' || $this->reason === 'invalid_client' || $this->reason === 'unauthorized_client';
    }

    /** La ressource n'existe plus côté Google (événement ou agenda supprimé). */
    public function isGone(): bool
    {
        return $this->httpStatus === 404 || $this->httpStatus === 410;
    }

    /** Panne passagère : réseau, limite de débit, erreur serveur. */
    public function isRetryable(): bool
    {
        return $this->httpStatus === 0 || $this->httpStatus === 429 || $this->httpStatus >= 500
            || ($this->httpStatus === 403 && in_array($this->reason, ['rateLimitExceeded', 'userRateLimitExceeded'], true));
    }
}
