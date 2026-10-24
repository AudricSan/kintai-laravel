<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Extension;

use kintai\Contract\Provider\Swap;
use kintai\Contract\Provider\SwapProvider;
use kintai\Core\BundleContract\Bundle;
use kintai\Core\BundleManager;
use kintai\Core\Extension\ExtensionRegistry;
use kintai\Core\Extension\Swaps;
use PHPUnit\Framework\TestCase;

/**
 * Échanges de shifts lus à travers des fournisseurs (contrat 1.18) : fournisseurs des bundles actifs seulement.
 */
final class SwapsTest extends TestCase
{
    /** @param array<string, mixed> $active slug => fournisseurs déclarés */
    private function registry(array $active): ExtensionRegistry
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

                public function swapProviders(): array
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

        return new ExtensionRegistry($manager);
    }

    /** @param list<Swap>|\Throwable $swaps */
    private static function provider(array|\Throwable $swaps, array &$calls = []): SwapProvider
    {
        return new class($swaps, $calls) implements SwapProvider {
            public function __construct(private readonly array|\Throwable $swaps, private array &$calls) {}

            public function swaps(?array $storeIds, ?string $from = null, ?string $to = null): array
            {
                $this->calls[] = $storeIds;
                if ($this->swaps instanceof \Throwable) {
                    throw $this->swaps;
                }
                return $this->swaps;
            }
        };
    }

    public function testDeclaredProvidersAreMerged(): void
    {
        $calls = [];
        $swaps = new Swaps($this->registry([
            'shift-swap' => [self::provider([new Swap(3, 4, 7, 8, '2026-10-01 09:00:00', 'pending')], $calls), 'pas un fournisseur'],
            'boom'       => [self::provider(new \RuntimeException('kaput'))],
        ]));

        $this->assertSame(
            [['id' => 3, 'store_id' => 4, 'requester_id' => 7, 'target_user_id' => 8, 'created_at' => '2026-10-01 09:00:00', 'status' => 'pending']],
            $swaps->forStore(4)
        );
        $this->assertSame([[4]], $calls);
    }

    /** Sans fournisseur (shift-swap désactivé ou désinstallé) : aucun échange à compter. */
    public function testWithoutProviderThereIsNoSwap(): void
    {
        $this->assertSame([], (new Swaps($this->registry(['shift-swap' => []])))->forStore(4));
        $this->assertSame([], (new Swaps())->forStore(4));
    }

    public function testInvalidDateIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Swap(1, 1, 1, 2, '01/10/2026', 'pending');
    }
}
