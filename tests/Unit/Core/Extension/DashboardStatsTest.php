<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Extension;

use kintai\Contract\Extension\DashboardStat;
use kintai\Core\BundleContract\Bundle;
use kintai\Core\BundleManager;
use kintai\Core\Extension\DashboardStats;
use kintai\Core\Extension\ExtensionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Cartes de statistiques du tableau de bord employé (contrat 1.19) : déclarations des bundles actifs
 * (Bundle::dashboardStats()), repli historique de timeoff et shift-swap, fonctionnalité par magasin, isolation.
 */
final class DashboardStatsTest extends TestCase
{
    /** @param array<string, mixed> $active slug => cartes déclarées (list, ou \Throwable levée) */
    private function stats(array $active): DashboardStats
    {
        $bundles = [];
        foreach ($active as $slug => $declared) {
            $bundle = new class extends Bundle {
                public string $slug = '';
                public mixed $declared = [];

                public function __construct() {}

                public function getName(): string
                {
                    return $this->slug;
                }

                public function register(): void {}

                public function dashboardStats(): array
                {
                    if ($this->declared instanceof \Throwable) {
                        throw $this->declared;
                    }
                    return $this->declared;
                }
            };
            $bundle->slug = $slug;
            $bundle->declared = $declared;
            $bundles['B' . $slug] = $bundle;
        }
        $manager = (new \ReflectionClass(BundleManager::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($manager, 'bundles'))->setValue($manager, $bundles);

        return new DashboardStats(new ExtensionRegistry($manager));
    }

    private static function keys(array $cards): array
    {
        return array_column($cards, 'key');
    }

    /** Plus de carte historique : un bundle actif qui ne déclare rien n'a pas de carte. */
    public function testNoCardWithoutDeclaration(): void
    {
        $all = fn(?string $f): bool => true;

        $this->assertSame([], $this->stats(['timeoff' => [], 'shift-swap' => []])->cards([], $all));
        $this->assertSame([], $this->stats([])->cards([], $all), 'aucun bundle actif : aucune carte');
    }

    public function testDeclaredCardsReplaceTheLegacyOnesAndAreIsolated(): void
    {
        $cards = $this->stats([
            'shift-swap' => [
                new DashboardStat('pending_swaps', 'swaps', fn(array $c): int => 40 + $c['user']['id'], '🔄', 'danger', 'swaps', 20),
                new DashboardStat('broken', 'x', fn(): int => throw new \RuntimeException('kaput')),
            ],
            'acme' => [new DashboardStat('acme_tasks', 'acme', fn(): string => '3/5', '🧩', order: 5), 'pas une carte'],
            'boom' => new \RuntimeException('kaput'),
        ])->cards(['user' => ['id' => 2]], fn(?string $f): bool => true);

        $this->assertSame(['acme_tasks', 'pending_swaps'], self::keys($cards));
        $this->assertSame(['3/5', 42], array_column($cards, 'value'));
    }

    public function testInvalidToneIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DashboardStat('acme', 'acme', fn(): int => 1, tone: 'pink');
    }
}
