<?php

namespace App\License;

/**
 * Réponse du LicenseManager, rangée en cinq cas :
 * - `valid` : licence valable (le jeton signé est joint) ;
 * - `refused` : refus explicite du serveur (`valid: false` + code d'erreur) : seul cas qui retire les droits ;
 * - `rejected` : requête refusée par le serveur (HTTP 401 : clé produit refusée ; 422 : corps incorrect), sans verdict sur la licence ;
 * - `accepted` : demande prise en compte (HTTP 202, `/register` : un e-mail de confirmation part) ;
 * - `unreachable` : réseau, HTTP 5xx, 429 ou JSON illisible : jamais une raison de retirer les droits.
 */
final class LicenseResult
{
    public const VALID = 'valid';

    public const REFUSED = 'refused';

    public const REJECTED = 'rejected';

    public const UNREACHABLE = 'unreachable';

    public const ACCEPTED = 'accepted';

    public function __construct(
        public readonly string $kind,
        public readonly ?string $error = null,
        public readonly ?string $token = null,
        public readonly ?string $status = null,
        public readonly ?string $type = null,
        public readonly ?string $expiresAt = null,
        public readonly ?int $maxActivations = null,
        public readonly ?int $httpStatus = null,
    ) {}

    public static function unreachable(?int $httpStatus = null): self
    {
        return new self(self::UNREACHABLE, 'unreachable', httpStatus: $httpStatus);
    }

    public static function rejected(string $error, ?int $httpStatus = null): self
    {
        return new self(self::REJECTED, $error, httpStatus: $httpStatus);
    }

    /** Trop de demandes (HTTP 429) : à réessayer plus tard. */
    public function isRateLimited(): bool
    {
        return $this->kind === self::REJECTED && $this->httpStatus === 429;
    }

    public function isAccepted(): bool
    {
        return $this->kind === self::ACCEPTED;
    }

    public function isValid(): bool
    {
        return $this->kind === self::VALID;
    }

    /** La clé produit (Bearer) est refusée par le serveur (HTTP 401). */
    public function isProductKeyRejected(): bool
    {
        return $this->kind === self::REJECTED && $this->httpStatus === 401;
    }

    public function isRefused(): bool
    {
        return $this->kind === self::REFUSED;
    }

    /** Le serveur a répondu sur la licence (valable ou refusée). */
    public function isAnswer(): bool
    {
        return $this->kind === self::VALID || $this->kind === self::REFUSED;
    }
}
