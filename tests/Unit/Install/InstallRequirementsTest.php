<?php

namespace Tests\Unit\Install;

use App\Install\InstallRequirements;
use Tests\TestCase;

class InstallRequirementsTest extends TestCase
{
    /**
     * @param  list<string>  $missing
     */
    private function requirements(string $php = '8.4.1', array $missing = [], bool $writable = true): InstallRequirements
    {
        return new InstallRequirements(
            '/srv/kintai',
            $php,
            fn (string $extension): bool => ! in_array($extension, $missing, true),
            fn (string $path): bool => $writable,
        );
    }

    public function test_everything_present_passes(): void
    {
        $requirements = $this->requirements();

        $this->assertTrue($requirements->passes());
        $this->assertSame([], $requirements->failures());
        $this->assertSame(['sqlite', 'mysql'], $requirements->availableDrivers());
    }

    public function test_old_php_blocks(): void
    {
        $requirements = $this->requirements('8.2.30');

        $this->assertFalse($requirements->passes());
        $this->assertCount(1, $requirements->failures());
    }

    public function test_missing_required_extension_blocks(): void
    {
        $this->assertFalse($this->requirements(missing: ['mbstring'])->passes());
    }

    public function test_one_database_driver_is_enough(): void
    {
        $requirements = $this->requirements(missing: ['pdo_mysql']);

        $this->assertTrue($requirements->passes());
        $this->assertSame(['sqlite'], $requirements->availableDrivers());
    }

    public function test_no_database_driver_blocks(): void
    {
        $requirements = $this->requirements(missing: ['pdo_mysql', 'pdo_sqlite']);

        $this->assertFalse($requirements->passes());
        $this->assertSame([], $requirements->availableDrivers());
    }

    public function test_recommended_extension_does_not_block_but_is_reported(): void
    {
        $requirements = $this->requirements(missing: ['gd']);

        $this->assertTrue($requirements->passes());
        $this->assertContains(false, array_column($requirements->checks(), 'ok'));
    }

    public function test_unwritable_folders_block(): void
    {
        $this->assertFalse($this->requirements(writable: false)->passes());
    }
}
