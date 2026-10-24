<?php

declare(strict_types=1);

namespace kintai\Core\Services;

use kintai\Core\Exceptions\PlanLimitExceededException;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;

/**
 * Applique les limites du plan gratuit (freemium), ou celles propres au
 * palier de la licence payante active (voir docs/architecture.md "Licensing &
 * Freemium"). `LicenseClientService::isPaidPlanActive()` (lecture locale sans
 * réseau) determine si un plan payant s'applique ; les chiffres eux-mêmes
 * viennent de `LicenseClientService::entitlements()` (payload du
 * license_token, vérifié cryptographiquement — jamais un champ non signé) :
 * une clé absente du JSON `limits` de la licence, ou aucun token vérifiable
 * pour une licence pourtant active, valent illimité pour cette dimension.
 */
final class PlanLimitService
{
    private const FREE_MAX_STORES = 1;
    private const FREE_MAX_EMPLOYEES = 15;
    private const FREE_MAX_ACTIVE_BUNDLES = 4;

    public function __construct(
        private readonly StoreRepositoryInterface $stores,
        private readonly UserRepositoryInterface $users,
        private readonly LicenseClientService $license,
    ) {
    }

    /**
     * Nombre max de bundles actifs simultanément, ou null si illimité.
     */
    public function maxActiveBundles(): ?int
    {
        return $this->limit('max_bundles', self::FREE_MAX_ACTIVE_BUNDLES);
    }

    /** Nombre max de magasins, ou null si illimité. */
    public function maxStores(): ?int
    {
        return $this->limit('max_stores', self::FREE_MAX_STORES);
    }

    /** Nombre max d'employés actifs, ou null si illimité. */
    public function maxEmployees(): ?int
    {
        return $this->limit('max_employees', self::FREE_MAX_EMPLOYEES);
    }

    public function currentStoreCount(): int
    {
        return $this->stores->countActive();
    }

    public function currentEmployeeCount(): int
    {
        return $this->users->countActive();
    }

    public function assertCanCreateStore(): void
    {
        $max = $this->maxStores();
        if ($max !== null && $this->stores->countActive() >= $max) {
            throw new PlanLimitExceededException(__('plan_limit_stores'));
        }
    }

    public function assertCanCreateEmployee(): void
    {
        $max = $this->maxEmployees();
        if ($max !== null && $this->users->countActive() >= $max) {
            throw new PlanLimitExceededException(__('plan_limit_employees'));
        }
    }

    /**
     * Plan gratuit -> plafond fixe local. Plan payant -> limite propre a CETTE
     * licence, lue depuis les entitlements verifies cryptographiquement (voir
     * LicenseClientService::entitlements(), jamais un champ non verifie) :
     * cle absente du JSON `limits` -> illimite pour cette dimension ; aucun
     * token verifiable (licence active mais pas encore resynchronisee, ou
     * ancien etat local) -> illimite, comportement historique avant les
     * paliers pour ne pas casser un client deja paye en attendant son
     * prochain "validate".
     */
    private function limit(string $key, int $freeDefault): ?int
    {
        if (!$this->license->isPaidPlanActive()) {
            return $freeDefault;
        }

        $entitlements = $this->license->entitlements();
        if ($entitlements === null) {
            return null;
        }

        $limits = $entitlements['limits'] ?? [];
        return array_key_exists($key, $limits) ? $limits[$key] : null;
    }
}
