<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use kintai\Core\Services\PdfCjkFontResolver;

final class PdfCjkFontResolverTest extends TestCase
{
    private string $tmpDir;
    private string|false $originalSystemRoot;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/kintai_cjk_font_test_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir . '/storage/fonts', 0775, true);
        putenv('KINTAI_STORAGE_PATH=' . $this->tmpDir . '/storage');

        // Isole aussi le repli "polices Windows" pour ne pas dépendre de ce qui
        // est réellement installé sur la machine qui exécute les tests.
        $this->originalSystemRoot = getenv('SystemRoot');
        putenv('SystemRoot=' . $this->tmpDir . '/no-such-windows-root');
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
        putenv($this->originalSystemRoot === false ? 'SystemRoot' : 'SystemRoot=' . $this->originalSystemRoot);
    }

    public function testResolveReturnsNullWhenNoFontAvailable(): void
    {
        $this->assertNull(PdfCjkFontResolver::resolve());
    }

    public function testResolveReturnsBundledFontWhenPresent(): void
    {
        file_put_contents($this->tmpDir . '/storage/fonts/NotoSansJP-Regular.ttf', 'fake-ttf-content');

        $font = PdfCjkFontResolver::resolve();

        $this->assertNotNull($font);
        $this->assertSame('notosansjp', $font['default']);
        $this->assertArrayHasKey('notosansjp', $font['data']);
        $this->assertSame([$this->tmpDir . '/storage/fonts'], $font['dir']);
    }

    public function testApplyToLeavesConfigUnchangedWhenNoFontAvailable(): void
    {
        $config = ['mode' => 'utf-8', 'format' => 'A4'];

        $this->assertSame($config, PdfCjkFontResolver::applyTo($config));
    }

    public function testApplyToMergesFontConfigWhenAvailable(): void
    {
        file_put_contents($this->tmpDir . '/storage/fonts/NotoSansJP-Regular.ttf', 'fake-ttf-content');

        $result = PdfCjkFontResolver::applyTo(['mode' => 'utf-8']);

        $this->assertSame('utf-8', $result['mode']);
        $this->assertSame('notosansjp', $result['default_font']);
        $this->assertArrayHasKey('fontDir', $result);
        $this->assertArrayHasKey('fontdata', $result);
    }
}
