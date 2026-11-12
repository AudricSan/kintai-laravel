<?php

namespace Tests\Feature\Install;

use App\Install\Installer;
use App\Install\InstallFailure;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\UsesTemporaryInstallation;
use Tests\TestCase;

class InstallerTest extends TestCase
{
    use UsesTemporaryInstallation;

    private Installer $installer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installer = $this->setUpInstallation();
    }

    protected function tearDown(): void
    {
        $this->tearDownInstallation();

        parent::tearDown();
    }

    private function key(): string
    {
        return 'base64:'.base64_encode(str_repeat('k', 32));
    }

    public function test_installs_on_sqlite_and_creates_the_owner(): void
    {
        // Sans clé d'application (premier démarrage) : l'installation en génère une dans `.env`.
        config(['app.key' => '']);

        $owner = $this->installer->install($this->ownerInput());

        $this->assertTrue($this->installer->isInstalled());
        $this->assertStringStartsWith('base64:', (string) config('app.key'));
        $this->assertFileExists($this->installDirectory.'/installed');
        $this->assertFileExists($this->installDirectory.'/database.sqlite');

        $env = (string) file_get_contents($this->installDirectory.'/.env');
        $this->assertMatchesRegularExpression('/^APP_KEY=base64:\S+$/m', $env);
        $this->assertStringContainsString('APP_ENV=production', $env);
        $this->assertStringContainsString('APP_DEBUG=false', $env);
        $this->assertStringContainsString('DB_CONNECTION=sqlite', $env);

        $this->assertSame('owner@example.test', $owner->email);
        $this->assertTrue($owner->fresh()->roles->contains('slug', 'owner'));
        $this->assertFalse($owner->must_change_password);
        $this->assertNotSame('Correct-Horse-Battery-9', $owner->password);
        $this->assertDatabaseHas('roles', ['slug' => 'manager']);
        $this->assertDatabaseHas('roles', ['slug' => 'employee']);
    }

    public function test_refuses_a_concurrent_installation_and_releases_the_guard(): void
    {
        $guard = fopen($this->installer->lockPath().'.installing', 'c');
        $this->assertTrue(flock($guard, LOCK_EX | LOCK_NB));

        try {
            $this->installer->install($this->ownerInput());
            $this->fail('A concurrent installation must be refused.');
        } catch (InstallFailure $e) {
            $this->assertSame(__('An installation is already in progress.'), $e->getMessage());
            $this->assertFileDoesNotExist($this->installDirectory.'/.env');
        } finally {
            flock($guard, LOCK_UN);
            fclose($guard);
        }

        $this->installer->install($this->ownerInput());
        $this->assertSame(1, User::query()->count());
    }

    public function test_refuses_when_already_installed_without_touching_anything(): void
    {
        $this->installer->install($this->ownerInput());
        $env = (string) file_get_contents($this->installDirectory.'/.env');

        try {
            $this->installer->install($this->ownerInput(['email' => 'second@example.test']), force: true);
            $this->fail('A second installation must be refused.');
        } catch (InstallFailure) {
            $this->assertSame($env, (string) file_get_contents($this->installDirectory.'/.env'));
            $this->assertSame(1, User::query()->count());
        }
    }

    public function test_an_existing_owner_counts_as_installed_and_writes_the_lock(): void
    {
        $this->installer->install($this->ownerInput());
        unlink($this->installDirectory.'/installed');

        $this->assertTrue($this->installer->isInstalled());
        $this->assertFileExists($this->installDirectory.'/installed');
    }

    public function test_invalid_input_writes_nothing(): void
    {
        try {
            $this->installer->install(['name' => '', 'email' => 'not-an-email', 'password' => 'short']);
            $this->fail('Validation must fail.');
        } catch (ValidationException $e) {
            $this->assertEqualsCanonicalizing(['name', 'email', 'password'], array_keys($e->errors()));
        }

        $this->assertFileDoesNotExist($this->installDirectory.'/.env');
        $this->assertFileDoesNotExist($this->installDirectory.'/installed');
    }

    public function test_password_confirmation_must_match_when_given(): void
    {
        $this->expectException(ValidationException::class);

        $this->installer->install($this->ownerInput(['password_confirmation' => 'different']));
    }

    public function test_mysql_requires_its_connection_fields(): void
    {
        try {
            $this->installer->install($this->ownerInput(['db_driver' => 'mysql']));
            $this->fail('Validation must fail.');
        } catch (ValidationException $e) {
            $this->assertEqualsCanonicalizing(['db_host', 'db_database', 'db_username'], array_keys($e->errors()));
        }
    }

    public function test_unknown_driver_and_multiline_values_are_rejected(): void
    {
        try {
            $this->installer->install($this->ownerInput(['db_driver' => 'pgsql']));
            $this->fail('Validation must fail.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('db_driver', $e->errors());
        }

        try {
            $this->installer->install($this->ownerInput([
                'db_driver' => 'mysql', 'db_host' => 'localhost', 'db_database' => "kintai\nAPP_DEBUG=true", 'db_username' => 'root',
            ]));
            $this->fail('Validation must fail.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('db_database', $e->errors());
        }
    }

    public function test_unreachable_database_fails_before_writing_the_database_settings(): void
    {
        if (! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_mysql is not available.');
        }

        try {
            $this->installer->install($this->ownerInput([
                'db_driver' => 'mysql', 'db_host' => '127.0.0.1', 'db_port' => 1, 'db_database' => 'kintai', 'db_username' => 'nobody',
            ]));
            $this->fail('The connection must fail.');
        } catch (InstallFailure) {
            $env = (string) file_get_contents($this->installDirectory.'/.env');
            $this->assertStringNotContainsString('DB_CONNECTION=mysql', $env);
            $this->assertFileDoesNotExist($this->installDirectory.'/installed');
        }
    }

    public function test_an_env_that_already_configures_a_database_is_kept_unless_forced(): void
    {
        $file = $this->installDirectory.'/mine.sqlite';
        file_put_contents($this->installDirectory.'/.env', 'APP_KEY='.$this->key()."\nDB_CONNECTION=sqlite\nDB_DATABASE=".$file."\n");
        config(['database.connections.sqlite.database' => $file]);

        $this->assertTrue($this->installer->databaseConfigured());

        try {
            $this->installer->install($this->ownerInput(['db_driver' => 'sqlite']));
            $this->fail('Explicit database options must be refused without force.');
        } catch (InstallFailure) {
            $this->assertFileDoesNotExist($this->installDirectory.'/installed');
        }

        // Sans option de base : le `.env` est utilisé tel quel.
        $this->installer->install($this->ownerInput());

        $this->assertFileExists($file);
        $this->assertStringContainsString('mine.sqlite', (string) file_get_contents($this->installDirectory.'/.env'));
    }

    public function test_force_replaces_the_configured_database(): void
    {
        file_put_contents($this->installDirectory.'/.env', 'APP_KEY='.$this->key()."\nDB_CONNECTION=sqlite\nDB_DATABASE=/nowhere/old.sqlite\n");

        $this->installer->install($this->ownerInput(['db_driver' => 'sqlite']), force: true);

        $env = (string) file_get_contents($this->installDirectory.'/.env');
        $this->assertStringNotContainsString('/nowhere/old.sqlite', $env);
        $this->assertStringContainsString('database.sqlite', $env);
    }
}
