<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Extension;

use kintai\Contract\Extension\StoreFeature;
use kintai\Core\BundleContract\Bundle;
use kintai\Core\BundleManager;
use kintai\Core\Extension\ExtensionRegistry;
use kintai\Core\Extension\StoreFeatures;
use PHPUnit\Framework\TestCase;

/**
 * Fonctionnalités par magasin construites à partir de données (étape 2e du contrat de bundle) : Core, déclarations
 * des bundles actifs (Bundle::storeFeatures(), contrat 1.3) et repli historique des bundles qui ne déclarent rien.
 */
final class StoreFeaturesTest extends TestCase
{
    /** @param array<string, mixed> $active slug => fonctionnalités déclarées (list, ou \Throwable levée) */
    private function features(array $active): StoreFeatures
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

                public function storeFeatures(): array
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

        return new StoreFeatures(new ExtensionRegistry($manager));
    }

    private static function keys(StoreFeatures $features): array
    {
        return $features->availableKeys();
    }

    public function testCoreOnlyWithoutBundles(): void
    {
        $this->assertSame(['shifts'], self::keys($this->features([])));
        $this->assertSame(['shifts'], (new StoreFeatures())->availableKeys());
    }

    public function testDeclaredFeaturesReplaceTheLegacyOnesOfTheirBundle(): void
    {
        $features = $this->features([
            'timeoff' => [new StoreFeature('timeoff_v2', 'feature_timeoff', 'feature_timeoff_desc', '🏖', 35)],
            'acme'    => [new StoreFeature('acme_board', 'acme_board', '', '🧩', 5)],
            'shift-swap' => [],
        ]);

        $this->assertSame(['acme_board', 'shifts', 'timeoff_v2'], self::keys($features), 'shift-swap sans déclaration : rien');
    }

    public function testBrokenOrDuplicateDeclarationsAreIgnored(): void
    {
        $features = $this->features([
            'acme'  => [new StoreFeature('acme_board', 'a'), 'not a feature'],
            'other' => [new StoreFeature('acme_board', 'stolen'), new StoreFeature('shifts', 'stolen')],
            'boom'  => new \RuntimeException('kaput'),
        ]);

        $available = $features->available();
        $this->assertSame(['shifts', 'acme_board'], array_map(fn(StoreFeature $f): string => $f->key, $available));
        $this->assertSame('feature_shifts', $available[0]->label);
        $this->assertSame('a', $available[1]->label);
    }

    public function testIsAvailable(): void
    {
        $features = $this->features(['acme' => [new StoreFeature('acme_board', 'a')]]);

        $this->assertTrue($features->isAvailable('shifts'));
        $this->assertTrue($features->isAvailable('acme_board'));
        $this->assertTrue($features->isAvailable('unknown_thing'), 'clé inconnue : pas une fonctionnalité de bundle');
    }

    public function testInvalidKeyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new StoreFeature('Bad Key', 'x');
    }
}
