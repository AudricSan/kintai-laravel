<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use kintai\Core\Services\UpdateService;

final class UpdateServiceTest extends TestCase
{
    private string $tmpDir;
    private UpdateService $service;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/kintai_update_test_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir . '/config', 0775, true);

        $this->service = new UpdateService($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $di = new \RecursiveDirectoryIterator($this->tmpDir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $fi = new \RecursiveIteratorIterator($di, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($fi as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->tmpDir);
    }

    private function writeAppVersion(string $version): void
    {
        file_put_contents(
            $this->tmpDir . '/config/app.php',
            '<?php return [\'version\' => ' . var_export($version, true) . '];'
        );
    }

    public function testDefaultVersionIs000WhenNoConfigFile(): void
    {
        $this->assertSame('0.0.0', $this->service->getCurrentVersion());
    }

    public function testGetCurrentVersionReadsConfigAppPhp(): void
    {
        $this->writeAppVersion('1.2.3');

        $this->assertSame('1.2.3', $this->service->getCurrentVersion());
    }

    public function testGetCurrentVersionReflectsConfigFileChanges(): void
    {
        $this->writeAppVersion('1.0.0');
        $this->assertSame('1.0.0', $this->service->getCurrentVersion());

        $this->writeAppVersion('2.0.0');
        $this->assertSame('2.0.0', $this->service->getCurrentVersion());
    }

    /**
     * setCurrentVersion() est ce que GithubUpdateService appelle après une
     * mise à jour appliquée, avec le tag exact (vrai Z inclus) — config/app.php
     * devient la seule source de vérité, sans fichier annexe.
     */
    public function testSetCurrentVersionRewritesConfigAppPhp(): void
    {
        $this->writeAppVersion('0.11.0');

        $this->service->setCurrentVersion('0.11.10');

        $this->assertSame('0.11.10', $this->service->getCurrentVersion());
    }

    public function testSetCurrentVersionCanBeCalledRepeatedly(): void
    {
        $this->writeAppVersion('0.11.0');

        $this->service->setCurrentVersion('0.11.10');
        $this->service->setCurrentVersion('0.11.11');

        $this->assertSame('0.11.11', $this->service->getCurrentVersion());
    }
}
