<?php

declare(strict_types=1);

namespace kintai\Core\Extension;

use kintai\Contract\Provider\Swap;
use kintai\Core\Container;
use kintai\Core\Services\Log;

/**
 * Demandes d'échange de shifts, lues à travers des fournisseurs (contrat 1.18) :
 * celles des fournisseurs déclarés par les bundles actifs (Bundle::swapProviders()), réunies. Sans fournisseur (bundle
 * shift-swap désactivé ou désinstallé), il n'y a pas d'échanges à compter.
 *
 * Les lignes renvoyées gardent la forme historique (id, store_id, requester_id, target_user_id, created_at, status).
 */
final class Swaps
{
    public function __construct(private readonly ?ExtensionRegistry $registry = null) {}

    /** Instance de la requête courante (registre des extensions), ou aucun échange si indisponible. */
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

    /** @return list<array<string, mixed>> Toutes les demandes d'échange du magasin. */
    public function forStore(int $storeId): array
    {
        $rows = [];
        foreach ($this->registry?->swapProvidersByBundle() ?? [] as $slug => $providers) {
            foreach ($providers as $provider) {
                try {
                    foreach ($provider->swaps([$storeId]) as $swap) {
                        if ($swap instanceof Swap) {
                            $rows[] = $swap->toArray();
                        }
                    }
                } catch (\Throwable $e) {
                    try {
                        Log::warning('bundle_provider_failed', ['bundle' => $slug, 'provider' => 'swaps', 'error' => $e->getMessage()]);
                    } catch (\Throwable) {
                    }
                }
            }
        }
        return $rows;
    }
}
