<?php

declare(strict_types=1);

namespace kintai\Core\Extension;

use kintai\Contract\Provider\Absence;
use kintai\Core\Container;
use kintai\Core\Services\Log;

/**
 * Absences des employés, lues à travers des fournisseurs (étape 3 du contrat de bundle) : celles des fournisseurs
 * déclarés par les bundles actifs (Bundle::absenceProviders(), contrat 1.10), additionnées. Sans fournisseur (bundle
 * timeoff désactivé ou désinstallé), il n'y a aucune absence.
 *
 * Les lignes renvoyées gardent la forme historique (id, user_id, store_id, start_date, end_date, type, status) pour
 * que les calculs du Core (planning, iCal, statistiques) restent inchangés.
 */
final class Absences
{
    public function __construct(private readonly ?ExtensionRegistry $registry = null) {}

    /** Instance de la requête courante (registre des extensions), ou aucune absence si indisponible. */
    public static function current(): self
    {
        try {
            $container = Container::getInstance();
            $registry = $container->has(ExtensionRegistry::class) ? $container->make(ExtensionRegistry::class) : null;
        } catch (\Throwable) {
            $registry = null;
        }
        return new self($registry);
    }

    /** @return list<array<string, mixed>> Absences de tous statuts de l'employé. */
    public function forUser(int $userId): array
    {
        return $this->rows([$userId], null);
    }

    /** @return list<array<string, mixed>> Absences de tous statuts du magasin. */
    public function forStore(int $storeId): array
    {
        return $this->rows(null, [$storeId]);
    }

    /**
     * Absences des fournisseurs déclarés (aucune si aucun bundle actif n'en déclare).
     *
     * @return list<array<string, mixed>>
     */
    private function rows(?array $userIds, ?array $storeIds): array
    {
        $rows = [];
        foreach ($this->registry?->absenceProvidersByBundle() ?? [] as $slug => $providers) {
            foreach ($providers as $provider) {
                try {
                    foreach ($provider->absences($userIds, $storeIds) as $absence) {
                        if ($absence instanceof Absence) {
                            $rows[] = $absence->toArray();
                        }
                    }
                } catch (\Throwable $e) {
                    try {
                        Log::warning('bundle_extension_failed', ['bundle' => $slug, 'point' => 'absenceProviders', 'error' => $e->getMessage()]);
                    } catch (\Throwable) {
                    }
                }
            }
        }
        return $rows;
    }
}
