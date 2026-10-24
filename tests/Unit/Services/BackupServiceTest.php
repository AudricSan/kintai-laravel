<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use kintai\Core\Services\BackupService;

final class BackupServiceTest extends TestCase
{
    private string $tmpDir;
    private string $backupDir;
    private BackupService $service;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/kintai_backup_test_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir . '/storage/backups', 0775, true);
        mkdir($this->tmpDir . '/storage/app', 0775, true);
        mkdir($this->tmpDir . '/storage/uploads', 0775, true);

        file_put_contents($this->tmpDir . '/storage/app/database.sqlite', '');
        file_put_contents($this->tmpDir . '/storage/uploads/test.txt', 'hello');

        putenv('KINTAI_STORAGE_PATH=' . $this->tmpDir . '/storage');

        $this->service = new BackupService(null);
        $ref = new \ReflectionProperty(BackupService::class, 'backupDir');
        $ref->setAccessible(true);
        $ref->setValue($this->service, $this->tmpDir . '/storage/backups');
    }

    protected function tearDown(): void
    {
        $di = new \RecursiveDirectoryIterator($this->tmpDir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $fi = new \RecursiveIteratorIterator($di, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($fi as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->tmpDir);
        putenv('KINTAI_STORAGE_PATH');
    }

    public function testCreateBackupReturnsArrayWithKeys(): void
    {
        $result = $this->service->create('test backup');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('filename', $result);
        $this->assertArrayHasKey('filepath', $result);
        $this->assertArrayHasKey('size', $result);
        $this->assertArrayHasKey('created_at', $result);
        $this->assertArrayHasKey('note', $result);
        $this->assertSame('test backup', $result['note']);
    }

    public function testCreateBackupCreatesZipFile(): void
    {
        $result = $this->service->create();
        $this->assertFileExists($result['filepath']);
        $this->assertGreaterThan(0, $result['size']);
    }

    public function testListReturnsArray(): void
    {
        $this->service->create('first');
        $this->service->create('second');
        $list = $this->service->list();
        $this->assertIsArray($list);
        $this->assertCount(2, $list);
    }

    public function testListBackupsSortedDescending(): void
    {
        $this->service->create();
        sleep(1);
        $this->service->create();
        $list = $this->service->list();
        $this->assertCount(2, $list);
        $this->assertGreaterThan($list[1]['created_at'], $list[0]['created_at']);
    }

    public function testDeleteRemovesFile(): void
    {
        $result = $this->service->create('to delete');
        $this->assertFileExists($result['filepath']);

        $deleted = $this->service->delete($result['filename']);
        $this->assertTrue($deleted);
        $this->assertFileDoesNotExist($result['filepath']);
    }

    public function testDeleteNonexistentReturnsFalse(): void
    {
        $this->assertFalse($this->service->delete('nonexistent_backup'));
    }

    public function testCreateBackupContainsSqliteAndUploads(): void
    {
        $result = $this->service->create();
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($result['filepath']));
        $this->assertSame(0, $zip->statName('database.sqlite') === false ? -1 : 0);
        $this->assertSame(0, $zip->statName('uploads/test.txt') === false ? -1 : 0);
        $zip->close();
    }

    public function testCreateCodeSnapshotExcludesStorageVendorAndGit(): void
    {
        $appDir = sys_get_temp_dir() . '/kintai_backup_app_' . bin2hex(random_bytes(4));
        mkdir($appDir . '/vendor', 0775, true);
        mkdir($appDir . '/.git', 0775, true);
        mkdir($appDir . '/src', 0775, true);
        file_put_contents($appDir . '/vendor/autoload.php', 'vendor');
        file_put_contents($appDir . '/.git/HEAD', 'git');
        file_put_contents($appDir . '/src/Foo.php', '<?php // foo');
        file_put_contents($appDir . '/composer.json', '{}');

        $filepath = $this->service->createCodeSnapshot('test', $appDir);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($filepath));
        $this->assertFalse($zip->statName('vendor/autoload.php'));
        $this->assertFalse($zip->statName('.git/HEAD'));
        $this->assertNotFalse($zip->statName('src/Foo.php'));
        $this->assertNotFalse($zip->statName('composer.json'));
        $zip->close();

        $di = new \RecursiveDirectoryIterator($appDir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $fi = new \RecursiveIteratorIterator($di, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($fi as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($appDir);
    }
}
