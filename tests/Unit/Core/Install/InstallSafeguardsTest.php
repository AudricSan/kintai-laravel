<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Install;

use App\Install\InstallCsrf;
use App\Install\InstallLock;
use PHPUnit\Framework\TestCase;

/** Audit SEC-01 : un POST cross-site ne peut pas installer, et deux installations simultanées sont refusées. */
final class InstallSafeguardsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kintai_install_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/storage', 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/storage/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir . '/storage');
        @rmdir($this->dir);
    }

    public function testAPostWithoutTheCookieIsRefusedEvenWithAToken(): void
    {
        $csrf = new InstallCsrf([]);

        $this->assertFalse($csrf->hadCookie());
        $this->assertFalse($csrf->verifies([InstallCsrf::FIELD => $csrf->token()]));
    }

    public function testAPostWithAnotherTokenThanTheCookieIsRefused(): void
    {
        $csrf = new InstallCsrf([InstallCsrf::COOKIE => str_repeat('a', 64)]);

        $this->assertTrue($csrf->hadCookie());
        $this->assertFalse($csrf->verifies([InstallCsrf::FIELD => str_repeat('b', 64)]));
        $this->assertFalse($csrf->verifies([]));
        $this->assertFalse($csrf->verifies([InstallCsrf::FIELD => ['a']]));
    }

    public function testAPostWithTheSameTokenAsTheCookieIsAccepted(): void
    {
        $csrf = new InstallCsrf([InstallCsrf::COOKIE => str_repeat('a', 64)]);

        $this->assertTrue($csrf->verifies([InstallCsrf::FIELD => str_repeat('a', 64)]));
    }

    public function testAMalformedCookieIsIgnoredAndReplacedByAFreshToken(): void
    {
        $csrf = new InstallCsrf([InstallCsrf::COOKIE => 'not-a-token']);

        $this->assertFalse($csrf->hadCookie());
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $csrf->token());
    }

    public function testASecondInstallationIsRefusedWhileTheFirstRuns(): void
    {
        $first  = new InstallLock($this->dir);
        $second = new InstallLock($this->dir);

        $this->assertTrue($first->acquire());
        $this->assertFalse($second->acquire(), 'deux installations simultanées');

        $first->release();
        $this->assertTrue($second->acquire(), 'le verrou est repris une fois libéré');
        $second->release();
    }

    public function testNoInstallationStartsOnAnInstanceAlreadyInstalled(): void
    {
        file_put_contents($this->dir . '/storage/installed.lock', 'x');

        $this->assertFalse((new InstallLock($this->dir))->acquire());
    }
}