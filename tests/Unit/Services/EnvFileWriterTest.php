<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Services;

use kintai\Core\Services\EnvFileWriter;
use PHPUnit\Framework\TestCase;

final class EnvFileWriterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kintai-env-' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->dir);
        unset($_ENV['KINTAI_TEST_VAR'], $_SERVER['KINTAI_TEST_VAR']);
        putenv('KINTAI_TEST_VAR');
    }

    public function testReplacesTheLineAndKeepsTheRest(): void
    {
        file_put_contents($this->dir . '/.env', "# commentaire\r\nAPP_NAME=Kintai\r\nKINTAI_TEST_VAR=fr\r\nAPP_DEBUG=false\r\n");

        (new EnvFileWriter($this->dir))->set('KINTAI_TEST_VAR', 'ja');

        $this->assertSame("# commentaire\r\nAPP_NAME=Kintai\r\nKINTAI_TEST_VAR=ja\r\nAPP_DEBUG=false\r\n", file_get_contents($this->dir . '/.env'));
        $this->assertSame('ja', env('KINTAI_TEST_VAR'));
    }

    public function testAppendsTheVariableAndCreatesTheFileWhenMissing(): void
    {
        (new EnvFileWriter($this->dir))->set('KINTAI_TEST_VAR', 'en');
        $this->assertSame("KINTAI_TEST_VAR=en\n", file_get_contents($this->dir . '/.env'));

        file_put_contents($this->dir . '/.env', 'APP_NAME=Kintai');
        (new EnvFileWriter($this->dir))->set('KINTAI_TEST_VAR', 'fr');
        $this->assertSame("APP_NAME=Kintai\nKINTAI_TEST_VAR=fr\n", file_get_contents($this->dir . '/.env'));
    }

    public function testWritesTheFileThatIsActuallyLoaded(): void
    {
        // kintai_load_env_file() ne charge que le premier fichier présent : .env.local avant .env.
        file_put_contents($this->dir . '/.env.local', "KINTAI_TEST_VAR=fr\n");
        file_put_contents($this->dir . '/.env', "KINTAI_TEST_VAR=fr\n");

        (new EnvFileWriter($this->dir))->set('KINTAI_TEST_VAR', 'ja');

        $this->assertSame("KINTAI_TEST_VAR=ja\n", file_get_contents($this->dir . '/.env.local'));
        $this->assertSame("KINTAI_TEST_VAR=fr\n", file_get_contents($this->dir . '/.env'));
    }

    public function testRefusesAValueThatWouldBreakTheFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new EnvFileWriter($this->dir))->set('KINTAI_TEST_VAR', "ja\nAPP_DEBUG=true");
    }
}
