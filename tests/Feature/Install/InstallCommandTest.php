<?php

namespace Tests\Feature\Install;

use App\Models\User;
use Tests\Concerns\UsesTemporaryInstallation;
use Tests\TestCase;

class InstallCommandTest extends TestCase
{
    use UsesTemporaryInstallation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInstallation();
    }

    protected function tearDown(): void
    {
        $this->tearDownInstallation();

        parent::tearDown();
    }

    public function test_installs_non_interactively(): void
    {
        $this->artisan('kintai:install', [
            '--name' => 'Aiko Owner',
            '--email' => 'owner@example.test',
            '--password' => 'Correct-Horse-Battery-9',
            '--no-interaction' => true,
        ])->assertExitCode(0);

        $this->assertFileExists($this->installDirectory.'/installed');
        $this->assertTrue(User::query()->where('email', 'owner@example.test')->firstOrFail()->roles->contains('slug', 'owner'));
    }

    public function test_local_flag_keeps_the_development_values(): void
    {
        $this->artisan('kintai:install', [
            '--name' => 'Aiko Owner',
            '--email' => 'owner@example.test',
            '--password' => 'Correct-Horse-Battery-9',
            '--local' => true,
            '--no-interaction' => true,
        ])->assertExitCode(0);

        $this->assertStringContainsString('APP_ENV=local', (string) file_get_contents($this->installDirectory.'/.env'));
    }

    public function test_invalid_options_fail_without_installing(): void
    {
        $this->artisan('kintai:install', ['--email' => 'nope', '--no-interaction' => true])->assertExitCode(1);

        $this->assertFileDoesNotExist($this->installDirectory.'/installed');
        $this->assertFileDoesNotExist($this->installDirectory.'/.env');
    }

    public function test_refuses_when_already_installed(): void
    {
        file_put_contents($this->installDirectory.'/installed', 'x');

        $this->artisan('kintai:install', [
            '--name' => 'Second',
            '--email' => 'second@example.test',
            '--password' => 'Correct-Horse-Battery-9',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertExitCode(1);

        $this->assertFileDoesNotExist($this->installDirectory.'/.env');
    }
}
