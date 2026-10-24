<?php

declare(strict_types=1);

namespace kintai\Core\Extension;

use kintai\Contract\Extension\DashboardStat;
use kintai\Core\Container;
use kintai\Core\Services\Log;

/**
 * Cartes de statistiques du tableau de bord employé apportées par les bundles (point d'extension
 * Bundle::dashboardStats(), contrat 1.19), déclarées par les bundles actifs.
 */
final class DashboardStats
{
    public function __construct(private readonly ?ExtensionRegistry $registry = null) {}

    /** Instance de la requête courante (registre des extensions), ou aucune carte si indisponible. */
    public static function current(): self
    {
        try {
            $container = Container::getInstance();
            return new self($container->has(ExtensionRegistry::class) ? $container->make(ExtensionRegistry::class) : null);
        } catch (\Throwable) {
            return new self();
        }
    }

    /**
     * Cartes déclarées par les bundles actifs, triées par ordre. Une clé déjà prise n'est pas reprise ; une carte dont
     * la valeur lève une exception est retirée.
     *
     * @param array<string, mixed>    $context
     * @param callable(?string): bool $feat         Fonctionnalité par magasin active ?
     * @return list<array{key: string, label: string, value: int|string, icon: string, tone: string}>
     */
    public function cards(array $context, callable $feat): array
    {
        $all = [];
        foreach ($this->registry?->dashboardStatsByBundle() ?? [] as $stats) {
            array_push($all, ...$stats);
        }
        usort($all, static fn(DashboardStat $a, DashboardStat $b): int => [$a->order, $a->key] <=> [$b->order, $b->key]);

        $cards = [];
        foreach ($all as $stat) {
            if (isset($cards[$stat->key]) || ($stat->storeFeature !== null && !$feat($stat->storeFeature))) {
                continue;
            }
            try {
                $value = ($stat->value)($context);
            } catch (\Throwable $e) {
                try {
                    Log::warning('bundle_extension_failed', ['point' => 'dashboardStats', 'key' => $stat->key, 'error' => $e->getMessage()]);
                } catch (\Throwable) {
                }
                continue;
            }
            $cards[$stat->key] = ['key' => $stat->key, 'label' => __($stat->label), 'value' => $value, 'icon' => $stat->icon, 'tone' => $stat->tone];
        }
        return array_values($cards);
    }
}
