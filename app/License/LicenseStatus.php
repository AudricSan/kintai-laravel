<?php

namespace App\License;

use Carbon\CarbonImmutable;

/**
 * État de la licence à un instant donné (voir `LicenseState`). `claims` ne contient que les revendications d'un jeton
 * dont la signature, la date, le produit et l'émetteur ont été vérifiés (sinon vide).
 */
final class LicenseStatus
{
    /** Pas de licence : palier gratuit. */
    public const FREE = 'free';

    /** Licence valable, dernier contact réussi. */
    public const ACTIVE = 'active';

    /** Licence valable d'après le dernier jeton, mais le serveur est injoignable : les droits sont gardés jusqu'à son expiration. */
    public const GRACE = 'grace';

    /** Licence expirée (refus du serveur ou jeton arrivé à échéance). */
    public const EXPIRED = 'expired';

    public const REVOKED = 'revoked';

    public const SUSPENDED = 'suspended';

    /** Refus explicite d'un autre ordre (non activée, introuvable, plus de siège, jeton invalide). */
    public const REFUSED = 'refused';

    /** Une clé est réglée mais le serveur est injoignable et aucun jeton valable ne couvre la licence. */
    public const UNREACHABLE = 'unreachable';

    /** Valeurs de `diagnostic` (dernier appel qui n'a pas abouti). */
    public const DIAGNOSTIC_UNREACHABLE = 'unreachable';

    public const DIAGNOSTIC_PRODUCT_KEY_REJECTED = 'product_key_rejected';

    /**
     * @param  array<string, mixed>  $claims
     */
    public function __construct(
        public readonly string $state,
        public readonly array $claims,
        public readonly ?string $refusal,
        public readonly ?CarbonImmutable $validatedAt,
        public readonly string $diagnostic = '',
    ) {}

    /** La clé produit est refusée par le serveur (HTTP 401) : à corriger côté configuration ; les droits du jeton valide restent honorés. */
    public function productKeyRejected(): bool
    {
        return $this->diagnostic === self::DIAGNOSTIC_PRODUCT_KEY_REJECTED;
    }

    /** La licence accorde-t-elle des droits ? */
    public function grantsRights(): bool
    {
        return $this->state === self::ACTIVE || $this->state === self::GRACE;
    }

    /** Une clé est réglée mais la licence ne vaut rien (refusée, expirée, suspendue, révoquée, jeton expiré hors ligne). */
    public function isProblem(): bool
    {
        return in_array($this->state, [self::EXPIRED, self::REVOKED, self::SUSPENDED, self::REFUSED, self::UNREACHABLE], true);
    }

    public function tier(): ?string
    {
        $tier = $this->claims['plan_tier'] ?? null;

        return is_string($tier) && $tier !== '' ? $tier : null;
    }

    /** Fin de la licence (pas celle du jeton) ; null = sans fin ou inconnue. */
    public function expiresAt(): ?CarbonImmutable
    {
        $value = $this->claims['expires_at'] ?? null;

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Fin de validité du jeton (hors ligne, les droits s'arrêtent là). */
    public function tokenExpiresAt(): ?CarbonImmutable
    {
        $exp = $this->claims['exp'] ?? null;

        return is_int($exp) ? CarbonImmutable::createFromTimestamp($exp) : null;
    }

    /**
     * @return array<string, int>
     */
    public function limits(): array
    {
        $limits = $this->claims['limits'] ?? null;

        if (! is_array($limits)) {
            return [];
        }

        return array_filter($limits, fn ($value, $key): bool => is_string($key) && is_int($value), ARRAY_FILTER_USE_BOTH);
    }
}
