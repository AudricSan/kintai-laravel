<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Install;

use kintai\Core\Install\InstallRequirements;
use PHPUnit\Framework\TestCase;

final class InstallRequirementsTest extends TestCase
{
    /** @param list<string> $missing extensions absentes ; $readOnly chemins non inscriptibles (suffixes) */
    private function requirements(string $php = '8.3.5', array $missing = [], array $readOnly = []): InstallRequirements
    {
        return new InstallRequirements(
            sys_get_temp_dir() . '/kintai_req_' . uniqid(),
            $php,
            static fn (string $ext): bool => !in_array($ext, $missing, true),
            static function (string $path) use ($readOnly): bool {
                foreach ($readOnly as $suffix) {
                    if (str_ends_with(str_replace('\\', '/', $path), $suffix)) {
                        return false;
                    }
                }
                return true;
            },
        );
    }

    /** @return array{key:string, label:string, ok:bool, blocking:bool} */
    private function check(InstallRequirements $r, string $label): array
    {
        foreach ($r->checks() as $check) {
            if (str_starts_with($check['label'], $label)) {
                return $check;
            }
        }
        $this->fail("Contrôle « {$label} » absent");
    }

    public function testEverythingAvailableAllowsInstallation(): void
    {
        $r = $this->requirements();
        $this->assertTrue($r->canInstall());
        $this->assertSame(['sqlite', 'mysql'], $r->availableDrivers());
    }

    public function testTooOldPhpBlocks(): void
    {
        $r = $this->requirements('8.2.20');
        $this->assertFalse($r->canInstall());
        $this->assertFalse($this->check($r, InstallRequirements::MIN_PHP)['ok']);
    }

    public function testOneDriverIsEnoughAndTheMissingOneDoesNotBlock(): void
    {
        $r = $this->requirements(missing: ['pdo_mysql']);
        $this->assertTrue($r->canInstall());
        $this->assertSame(['sqlite'], $r->availableDrivers());
        $this->assertFalse($r->driverAvailable('mysql'));
        $mysql = $this->check($r, 'pdo_mysql');
        $this->assertFalse($mysql['ok']);
        $this->assertFalse($mysql['blocking']);
    }

    public function testNoDriverAtAllBlocks(): void
    {
        $r = $this->requirements(missing: ['pdo_mysql', 'pdo_sqlite']);
        $this->assertFalse($r->canInstall());
        $this->assertTrue($this->check($r, 'pdo_sqlite')['blocking']);
    }

    public function testMissingRequiredExtensionBlocks(): void
    {
        $this->assertFalse($this->requirements(missing: ['mbstring'])->canInstall());
    }

    public function testMissingRecommendedExtensionOnlyWarns(): void
    {
        $r = $this->requirements(missing: ['zip', 'gd']);
        $this->assertTrue($r->canInstall());
        $this->assertFalse($this->check($r, 'zip')['ok']);
        $this->assertFalse($this->check($r, 'zip')['blocking']);
    }

    public function testReadOnlyStorageBlocks(): void
    {
        $r = $this->requirements(readOnly: ['/storage']);
        $this->assertFalse($r->canInstall());
        $this->assertFalse($this->check($r, 'storage/')['ok']);
    }
}
