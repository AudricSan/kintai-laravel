<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use kintai\Core\InstalledBundleAutoloader;
use kintai\Core\InstalledBundleManifestStore;
use PHPUnit\Framework\TestCase;

final class InstalledBundleAutoloaderTest extends TestCase
{
    public function testRegisterMakesAnInstalledBundleClassAutoloadable(): void
    {
        $bundlesDir = sys_get_temp_dir() . '/kintai-autoloader-' . uniqid();
        $slug = 'autoloader-fixture';
        $version = '1.0.0';
        $className = 'AutoloaderFixtureBundle_' . uniqid();
        $namespace = 'kintai\\Bundles\\Installed\\' . $className;

        $bundleRoot = $bundlesDir . '/' . $slug . '/' . $version;
        mkdir($bundleRoot . '/src/Controllers', 0777, true);

        file_put_contents($bundleRoot . '/bundle.json', json_encode([
            'slug'        => $slug,
            'name'        => 'Fixture',
            'version'     => $version,
            'namespace'   => $namespace,
            'entry_class' => $namespace . '\\Main',
        ]));

        file_put_contents($bundleRoot . '/src/Main.php', <<<PHP
        <?php
        namespace {$namespace};
        final class Main {}
        PHP);

        file_put_contents($bundleRoot . '/src/Controllers/Sub.php', <<<PHP
        <?php
        namespace {$namespace}\\Controllers;
        final class Sub {}
        PHP);

        $store = new InstalledBundleManifestStore($bundlesDir . '/installed.json');
        $store->setActiveVersion($slug, $version);

        (new InstalledBundleAutoloader($store, $bundlesDir))->register();

        $this->assertTrue(class_exists($namespace . '\\Main'));
        $this->assertTrue(class_exists($namespace . '\\Controllers\\Sub'));
    }

    public function testRegisterSkipsAnEntryWithNoBundleJson(): void
    {
        $bundlesDir = sys_get_temp_dir() . '/kintai-autoloader-' . uniqid();
        mkdir($bundlesDir, 0777, true);

        $store = new InstalledBundleManifestStore($bundlesDir . '/installed.json');
        $store->setActiveVersion('ghost-bundle', '1.0.0');

        // Ne doit pas planter même si storage/bundles/ghost-bundle/1.0.0 n'existe pas.
        (new InstalledBundleAutoloader($store, $bundlesDir))->register();

        $this->assertFalse(class_exists('kintai\\Bundles\\Installed\\GhostBundle\\Main', false));
    }
}
