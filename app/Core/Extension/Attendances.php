<?php

declare(strict_types=1);

namespace kintai\Core\Extension;

use kintai\Contract\Provider\Attendance;
use kintai\Core\Container;
use kintai\Core\Services\Log;

/**
 * Pointages des employés, lus à travers des fournisseurs (étape 3 du contrat de bundle) : ceux des fournisseurs
 * déclarés par les bundles actifs (Bundle::attendanceProviders(), contrat 1.11), additionnés. Sans fournisseur (bundle
 * timeclock désactivé ou désinstallé), il n'y a aucun pointage.
 *
 * Les lignes renvoyées gardent la forme historique (id, user_id, store_id, shift_date, clock_in_time, clock_out_time).
 */
final class Attendances
{
    public function __construct(private readonly ?ExtensionRegistry $registry = null) {}

    /** Instance de la requête courante (registre des extensions), ou aucun pointage si indisponible. */
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

    /** @return list<array<string, mixed>> Tous les pointages. */
    public function all(): array
    {
        return $this->rows(null, null, null, null);
    }

    /** @return list<array<string, mixed>> Pointages du magasin pour les jours travaillés de la période (bornes incluses). */
    public function forStoreBetween(int $storeId, string $from, string $to): array
    {
        return $this->rows(null, [$storeId], $from, $to);
    }

    /** Pointage en cours (sans départ) de l'employé, le plus récent, ou null. */
    public function activeForUser(int $userId): ?array
    {
        $open = array_values(array_filter($this->rows([$userId], null, null, null), static fn(array $r): bool => empty($r['clock_out_time'])));
        usort($open, static fn(array $a, array $b): int => strcmp((string) $b['clock_in_time'], (string) $a['clock_in_time']));
        return $open[0] ?? null;
    }

    /**
     * Pointages des fournisseurs déclarés (aucun si aucun bundle actif n'en déclare).
     *
     * @return list<array<string, mixed>>
     */
    private function rows(?array $userIds, ?array $storeIds, ?string $from, ?string $to): array
    {
        $rows = [];
        foreach ($this->registry?->attendanceProvidersByBundle() ?? [] as $slug => $providers) {
            foreach ($providers as $provider) {
                try {
                    foreach ($provider->attendance($userIds, $storeIds, $from, $to) as $attendance) {
                        if ($attendance instanceof Attendance) {
                            $rows[] = $attendance->toArray();
                        }
                    }
                } catch (\Throwable $e) {
                    try {
                        Log::warning('bundle_extension_failed', ['bundle' => $slug, 'point' => 'attendanceProviders', 'error' => $e->getMessage()]);
                    } catch (\Throwable) {
                    }
                }
            }
        }
        return $rows;
    }
}
