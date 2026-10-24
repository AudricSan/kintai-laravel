<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use kintai\Core\InstalledBundleManifestStore;
use PHPUnit\Framework\TestCase;

final class InstalledBundleManifestStoreTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/kintai-installed-store-' . uniqid() . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testAllReturnsEmptyArrayWhenFileDoesNotExist(): void
    {
        $store = new InstalledBundleManifestStore($this->path);

        $this->assertSame([], $store->all());
    }

    public function testSetActiveVersionThenAllReturnsIt(): void
    {
        $store = new InstalledBundleManifestStore($this->path);

        $store->setActiveVersion('feedback', '1.0.0');

        $this->assertSame(['feedback' => ['active_version' => '1.0.0']], $store->all());
    }

    public function testSetActiveVersionOverwritesAPreviousVersion(): void
    {
        $store = new InstalledBundleManifestStore($this->path);

        $store->setActiveVersion('feedback', '1.0.0');
        $store->setActiveVersion('feedback', '1.1.0');

        $this->assertSame(['feedback' => ['active_version' => '1.1.0']], $store->all());
    }

    public function testRemoveDeletesTheEntry(): void
    {
        $store = new InstalledBundleManifestStore($this->path);
        $store->setActiveVersion('feedback', '1.0.0');

        $store->remove('feedback');

        $this->assertSame([], $store->all());
    }

    public function testMultipleBundlesCoexist(): void
    {
        $store = new InstalledBundleManifestStore($this->path);

        $store->setActiveVersion('feedback', '1.0.0');
        $store->setActiveVersion('shift-claim', '2.3.1');

        $all = $store->all();
        $this->assertCount(2, $all);
        $this->assertSame('1.0.0', $all['feedback']['active_version']);
        $this->assertSame('2.3.1', $all['shift-claim']['active_version']);
    }
}
