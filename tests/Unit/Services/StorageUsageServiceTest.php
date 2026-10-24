<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Services;

use kintai\Core\Services\StorageUsageService;
use PHPUnit\Framework\TestCase;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 3));
}

/**
 * Bandeau « stockage limité » réservé à l'Owner : seuil (1 Go libre ou 10 % du disque), taille des archives de
 * sauvegarde, cache de la mesure. Le disque est simulé par des fonctions injectées.
 */
final class StorageUsageServiceTest extends TestCase
{
    private const GB = 1073741824;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kintai_storage_usage_' . uniqid();
        mkdir($this->dir . '/backups', 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{backups/*,cache/*,*}', GLOB_BRACE) ?: [] as $f) {
            is_file($f) ? unlink($f) : null;
        }
        @rmdir($this->dir . '/backups');
        @rmdir($this->dir . '/cache');
        @rmdir($this->dir);
    }

    private function service(int $free, int $total, ?string $cache = ''): StorageUsageService
    {
        return new StorageUsageService($this->dir, fn() => $free, fn() => $total, $cache);
    }

    public function testPlentyOfSpaceShowsNothing(): void
    {
        $this->assertNull($this->service(200 * self::GB, 500 * self::GB)->lowStorage());
    }

    public function testUnderOneGigabyteFreeIsLow(): void
    {
        $this->assertNotNull($this->service(self::GB - 1, 5000 * self::GB)->lowStorage());
    }

    public function testUnderTenPercentFreeIsLowEvenWithManyGigabytes(): void
    {
        $this->assertNotNull($this->service(40 * self::GB, 500 * self::GB)->lowStorage());
    }

    public function testExactlyTenPercentFreeIsNotLow(): void
    {
        $this->assertNull($this->service(50 * self::GB, 500 * self::GB)->lowStorage());
    }

    public function testTheBannerCarriesReadableFiguresAndTheBackupsSize(): void
    {
        file_put_contents($this->dir . '/backups/backup_1.zip', str_repeat('a', 2048));
        file_put_contents($this->dir . '/backups/code_avant-maj_1.zip', str_repeat('b', 1024));
        file_put_contents($this->dir . '/backups/notes.txt', 'ignoré');

        $low = $this->service(5 * self::GB / 10, 10 * self::GB)->lowStorage();

        $this->assertSame(['free' => '512.0 MB', 'total' => '10.0 GB', 'percent' => 5, 'backups' => '3.0 KB'], $low);
    }

    public function testAnUnreadableDiskShowsNothing(): void
    {
        $service = new StorageUsageService($this->dir, fn() => false, fn() => false, '');

        $this->assertNull($service->lowStorage());
    }

    public function testAnEmptyDiskSizeIsIgnored(): void
    {
        $this->assertFalse(StorageUsageService::isLow(0, 0));
    }

    public function testTheMeasureIsCachedForAFewMinutes(): void
    {
        $cache = $this->dir . '/cache/storage-usage.json';

        $this->assertNotNull($this->service(self::GB / 2, 10 * self::GB, $cache)->lowStorage());
        $this->assertFileExists($cache);

        // Le disque semble maintenant libre, mais la mesure en cache est encore valable.
        $this->assertNotNull($this->service(900 * self::GB, 1000 * self::GB, $cache)->lowStorage());

        // Cache périmé : nouvelle mesure.
        touch($cache, time() - 3600);
        $this->assertNull($this->service(900 * self::GB, 1000 * self::GB, $cache)->lowStorage());
    }

    public function testFormatBytes(): void
    {
        $this->assertSame('0 B', StorageUsageService::formatBytes(0));
        $this->assertSame('1.0 KB', StorageUsageService::formatBytes(1024));
        $this->assertSame('1.5 GB', StorageUsageService::formatBytes((int) (1.5 * self::GB)));
    }
}
