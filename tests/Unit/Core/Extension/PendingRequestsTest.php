<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Extension;

use kintai\Contract\Provider\PendingRequest;
use kintai\Contract\Provider\PendingRequestsProvider;
use kintai\Core\BundleContract\Bundle;
use kintai\Core\BundleManager;
use kintai\Core\Container;
use kintai\Core\Extension\ExtensionRegistry;
use kintai\Core\Extension\PendingRequests;
use PHPUnit\Framework\TestCase;

/**
 * Fournisseurs de demandes en attente (étape 3 du contrat de bundle, contrat 1.9) : déclarations des bundles actifs,
 * filtres permission/fonctionnalité, isolation, compteur et demandes anciennes.
 */
final class PendingRequestsTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    private static function provider(string $key, array $requests, string $permission = 'acme.view', ?string $feature = null, int $order = 100, ?\Throwable $fail = null): PendingRequestsProvider
    {
        return new class($key, $requests, $permission, $feature, $order, $fail) implements PendingRequestsProvider {
            public function __construct(private string $k, private array $r, private string $p, private ?string $f, private int $o, private ?\Throwable $fail) {}
            public function key(): string { return $this->k; }
            public function label(): string { return 'pending_' . $this->k; }
            public function emptyLabel(): string { return 'none'; }
            public function permission(): string { return $this->p; }
            public function storeFeature(): ?string { return $this->f; }
            public function order(): int { return $this->o; }
            public function manageUrl(): string { return '/' . $this->k; }

            public function pending(?array $storeIds): array
            {
                if ($this->fail !== null) {
                    throw $this->fail;
                }
                return array_values(array_filter($this->r, fn(PendingRequest $r): bool => $storeIds === null || in_array($r->storeId, $storeIds, true)));
            }
        };
    }

    /** @param array<string, list<PendingRequestsProvider>> $active slug => fournisseurs déclarés */
    private function pending(array $active): PendingRequests
    {
        $bundles = [];
        foreach ($active as $slug => $declared) {
            $bundle = new class extends Bundle {
                public string $slug = '';
                public array $declared = [];

                public function __construct() {}

                public function getName(): string
                {
                    return $this->slug;
                }

                public function register(): void {}

                public function pendingRequestsProviders(): array
                {
                    return $this->declared;
                }
            };
            $bundle->slug = $slug;
            $bundle->declared = $declared;
            $bundles['B' . $slug] = $bundle;
        }
        $manager = (new \ReflectionClass(BundleManager::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($manager, 'bundles'))->setValue($manager, $bundles);

        return new PendingRequests(new ExtensionRegistry($manager));
    }

    private static function all(): \Closure
    {
        return static fn(): bool => true;
    }

    /** Plus de repli : un bundle actif qui ne déclare rien n'a pas de section (le Core n'en connaît aucune). */
    public function testNoSectionWithoutDeclaredProvider(): void
    {
        $this->assertSame([], $this->pending(['timeoff' => []])->sections([1], self::all(), self::all()));
        $this->assertSame([], $this->pending([])->sections(null, self::all(), self::all()), 'bundle inactif : aucune section');
    }

    public function testDeclaredProvidersReplaceLegacyAndAreFiltered(): void
    {
        $old = new PendingRequest(7, 1, 4, '2026-09-30 08:00:00');
        $pending = $this->pending([
            'timeoff' => [self::provider('leave', [$old, new PendingRequest(8, 2, 4, '2026-10-14 08:00:00')], order: 5)],
            'acme'    => [
                self::provider('secret', [$old], permission: 'acme.secret'),
                self::provider('featured', [$old], feature: 'acme_feature'),
                self::provider('broken', [], fail: new \RuntimeException('kaput')),
                self::provider('leave', [$old]),
            ],
        ]);
        $can = static fn(string $p): bool => $p !== 'acme.secret';
        $feat = static fn(?string $f): bool => $f !== 'acme_feature';

        $sections = $pending->sections(null, $can, $feat);

        $this->assertSame(['leave'], array_column($sections, 'key'), 'timeoff déclare : plus de repli ; doublon, permission, fonctionnalité et échec filtrés');
        $this->assertSame('/leave', $sections[0]['manage_url']);
        $this->assertSame(2, $pending->count(null, $can, $feat));
        $this->assertSame(1, $pending->count([1], $can, $feat));
        $this->assertTrue($pending->hasVisible($can, $feat));
        $this->assertFalse($pending->hasVisible(static fn(): bool => false, $feat));
    }

    public function testOlderThanMergesAllKindsOldestFirst(): void
    {
        $pending = $this->pending([
            'timeoff' => [self::provider('timeoff', [new PendingRequest(1, 1, 4, '2026-10-01 09:00:00'), new PendingRequest(2, 2, 5, '2026-10-13 09:00:00')])],
            'acme'    => [self::provider('acme', [new PendingRequest(9, 1, 4, '2026-09-15 10:00:00'), new PendingRequest(10, 1, 4, '')])],
        ]);

        $old = $pending->olderThan([1, 2], '2026-10-10 00:00:00');

        $this->assertSame([['type' => 'acme', 'label' => 'pending_acme', 'id' => 9, 'store_id' => 1, 'created_at' => '2026-09-15 10:00:00'], ['type' => 'timeoff', 'label' => 'pending_timeoff', 'id' => 1, 'store_id' => 1, 'created_at' => '2026-10-01 09:00:00']], $old);
    }

    public function testInvalidColumnIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PendingRequest(1, 1, null, '', [['label' => 'x', 'value' => 1, 'style' => 'bold']]);
    }
}
