<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use kintai\Core\BundleContract\Bundle;
use kintai\Core\BundleManager;
use kintai\Core\Container;
use kintai\Tests\Support\FakeBundleManagerFactory;
use PHPUnit\Framework\TestCase;

final class FakeHelperAssetBundle extends Bundle
{
    public function getName(): string
    {
        return 'notebook';
    }

    public function getVersion(): string
    {
        return '1.2.3';
    }

    public function register(): void
    {
    }

    public function getAssetsPath(): ?string
    {
        return '/fake/notebook/public';
    }
}

/**
 * bundle_asset()/bundle_asset_path() doivent échouer "silencieusement" (null,
 * jamais d'exception) dès que le bundle est inactif ou n'a jamais déclaré
 * d'assets — une vue qui fait `if ($css = bundle_asset(...))` ne doit jamais
 * planter, même avant que le container soit prêt ou pour un bundle absent.
 */
final class HelpersBundleAssetTest extends TestCase
{
    protected function tearDown(): void
    {
        $instance = new \ReflectionProperty(Container::class, 'instance');
        $instance->setValue(null, null);
    }

    public function testReturnsNullWhenBundleInactive(): void
    {
        Container::getInstance()->instance(
            BundleManager::class,
            FakeBundleManagerFactory::withActiveSlugs(['timeoff']),
        );

        $this->assertNull(bundle_asset('notebook', 'css/notebook.css'));
        $this->assertNull(bundle_asset_path('notebook', 'css/notebook.css'));
    }

    public function testReturnsUrlAndPathWhenBundleActiveWithAssets(): void
    {
        $manager = (new \ReflectionClass(BundleManager::class))->newInstanceWithoutConstructor();
        $bundle = (new \ReflectionClass(FakeHelperAssetBundle::class))->newInstanceWithoutConstructor();

        $bundlesProperty = new \ReflectionProperty(BundleManager::class, 'bundles');
        $bundlesProperty->setAccessible(true);
        $bundlesProperty->setValue($manager, [FakeHelperAssetBundle::class => $bundle]);

        Container::getInstance()->instance(BundleManager::class, $manager);

        $url = bundle_asset('notebook', 'css/notebook.css');
        $this->assertNotNull($url);
        $this->assertStringContainsString('/bundle-assets/notebook/css/notebook.css', $url);
        $this->assertStringContainsString('v=1.2.3', $url);

        $this->assertSame('/fake/notebook/public/css/notebook.css', bundle_asset_path('notebook', 'css/notebook.css'));
    }

    public function testReturnsNullWhenContainerHasNoBundleManagerYet(): void
    {
        // N'appelle jamais Container::getInstance()->instance(BundleManager::class, ...) :
        // simule le tout début du boot, avant que BundleServiceProvider n'ait tourné.
        $this->assertNull(bundle_asset('notebook', 'css/notebook.css'));
        $this->assertNull(bundle_asset_path('notebook', 'css/notebook.css'));
    }
}
