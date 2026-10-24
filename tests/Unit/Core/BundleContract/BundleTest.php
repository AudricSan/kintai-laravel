<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\BundleContract;

use kintai\Core\BundleContract\Bundle;
use PHPUnit\Framework\TestCase;

final class BundleTest extends TestCase
{
    public function testGetVersionDefaultsToZeroForBundlesWithoutAManifest(): void
    {
        $bundle = (new \ReflectionClass(FakeBundle::class))->newInstanceWithoutConstructor();

        $this->assertSame('0.0.0', $bundle->getVersion());
    }

    public function testGetVersionCanBeOverriddenByAnInstalledBundle(): void
    {
        $bundle = (new \ReflectionClass(FakeVersionedBundle::class))->newInstanceWithoutConstructor();

        $this->assertSame('1.2.0', $bundle->getVersion());
    }

    public function testGetLabelDefaultsToATitleisedSlug(): void
    {
        $bundle = (new \ReflectionClass(FakeBundle::class))->newInstanceWithoutConstructor();

        $this->assertSame('Fake Bundle', $bundle->getLabel());
    }

    public function testResolvePathReturnsTheClassDirectoryForALegacyBundle(): void
    {
        $dir = sys_get_temp_dir() . '/kintai-legacy-bundle-' . uniqid();
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/LegacyStyleBundle.php', <<<'PHP'
            <?php
            namespace kintai\Tests\Fixtures\LegacyStyle;
            final class LegacyStyleBundle extends \kintai\Core\BundleContract\Bundle
            {
                public function getName(): string { return 'legacy-style'; }
                public function register(): void {}
            }
            PHP);
        require $dir . '/LegacyStyleBundle.php';

        $bundle = (new \ReflectionClass(\kintai\Tests\Fixtures\LegacyStyle\LegacyStyleBundle::class))->newInstanceWithoutConstructor();
        $resolvePath = new \ReflectionMethod(Bundle::class, 'resolvePath');
        $resolvePath->setAccessible(true);

        $this->assertSame(realpath($dir), realpath($resolvePath->invoke($bundle)));
    }

    public function testResolvePathReturnsTheParentOfSrcForAnInstalledBundle(): void
    {
        $root = sys_get_temp_dir() . '/kintai-installed-bundle-' . uniqid();
        mkdir($root . '/src', 0777, true);
        touch($root . '/bundle.json');
        file_put_contents($root . '/src/InstalledStyleBundle.php', <<<'PHP'
            <?php
            namespace kintai\Tests\Fixtures\InstalledStyle;
            final class InstalledStyleBundle extends \kintai\Core\BundleContract\Bundle
            {
                public function getName(): string { return 'installed-style'; }
                public function register(): void {}
            }
            PHP);
        require $root . '/src/InstalledStyleBundle.php';

        $bundle = (new \ReflectionClass(\kintai\Tests\Fixtures\InstalledStyle\InstalledStyleBundle::class))->newInstanceWithoutConstructor();
        $resolvePath = new \ReflectionMethod(Bundle::class, 'resolvePath');
        $resolvePath->setAccessible(true);

        $this->assertSame(realpath($root), realpath($resolvePath->invoke($bundle)));
    }
}

final class FakeBundle extends Bundle
{
    public function getName(): string
    {
        return 'fake-bundle';
    }

    public function register(): void
    {
    }
}

final class FakeVersionedBundle extends Bundle
{
    public function getName(): string
    {
        return 'fake-versioned-bundle';
    }

    public function getVersion(): string
    {
        return '1.2.0';
    }

    public function register(): void
    {
    }
}
