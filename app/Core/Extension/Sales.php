<?php

declare(strict_types=1);

namespace kintai\Core\Extension;

use kintai\Contract\Provider\DailySales;
use kintai\Core\Container;
use kintai\Core\Services\Log;

/**
 * Ventes journalières des magasins, lues auprès des fournisseurs déclarés par les bundles actifs
 * (Bundle::salesProviders(), contrat 1.14) et réunies — exposé aux bundles sous le nom kintai\Contract\Services\Sales.
 * Sans fournisseur actif (daily-report absent ou désactivé), il n'y a pas de ventes : la page de rentabilité d'un
 * magasin n'est alors pas proposée (available()).
 */
final class Sales
{
    public function __construct(private readonly ?ExtensionRegistry $registry = null) {}

    /** Instance de la requête courante (registre des extensions du conteneur). */
    public static function current(): self
    {
        try {
            $container = Container::getInstance();
            return new self($container->has(ExtensionRegistry::class) ? $container->make(ExtensionRegistry::class) : null);
        } catch (\Throwable) {
            return new self();
        }
    }

    /** Au moins un bundle actif fournit-il des ventes ? */
    public function available(): bool
    {
        return ($this->registry?->salesProvidersByBundle() ?? []) !== [];
    }

    /**
     * Ventes du magasin pour chaque jour de la période (bornes incluses), en valeurs du jour, triées par date.
     *
     * @param list<string>|null $statuses Ne garder que les jours à ces statuts (avant la conversion en valeurs du jour)
     * @return list<array{report_date: string, status: string, sales_total: float, labor_cost: float, customer_count: int, waste_total: float}>
     */
    public function dailySales(int $storeId, string $from, string $to, ?array $statuses = null): array
    {
        $rows = [];
        foreach ($this->registry?->salesProvidersByBundle() ?? [] as $slug => $providers) {
            foreach ($providers as $provider) {
                try {
                    foreach ($provider->dailySales($storeId, $from, $to, $statuses) as $day) {
                        if ($day instanceof DailySales) {
                            $rows[] = $day->toArray();
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning('bundle_provider_failed', ['bundle' => $slug, 'provider' => 'sales', 'error' => $e->getMessage()]);
                }
            }
        }
        usort($rows, static fn(array $a, array $b): int => strcmp($a['report_date'], $b['report_date']));
        return $rows;
    }
}
