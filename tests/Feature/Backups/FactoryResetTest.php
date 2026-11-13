<?php

namespace Tests\Feature\Backups;

use App\Actions\Backups\FactoryReset;
use App\Backups\BackupException;
use App\Backups\BackupInfo;
use App\Backups\BackupManager;
use App\Install\Installer;
use App\Models\Store;
use App\Models\User;
use App\Settings\AppSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\UsesBackupDirectory;
use Tests\Concerns\UsesTemporaryInstallation;
use Tests\TestCase;

/**
 * Pas de `RefreshDatabase` : la réinitialisation supprime les tables (`migrate:fresh`), ce qui ne se fait pas dans la
 * transaction de ce trait. La base SQLite est un fichier jetable du dossier temporaire, jamais la base du projet.
 */
class FactoryResetTest extends TestCase
{
    use CreatesAccess;
    use UsesBackupDirectory;
    use UsesTemporaryInstallation;

    private User $owner;

    private string $envFile;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBackupDirectory();
        $installer = $this->setUpInstallation();

        $this->key = 'base64:'.base64_encode(str_repeat('k', 32));
        $file = $this->installDirectory.'/mine.sqlite';
        $this->envFile = $this->installDirectory.'/.env';
        file_put_contents($this->envFile, 'APP_KEY='.$this->key."\nDB_CONNECTION=sqlite\nDB_DATABASE=".$file."\n");
        config(['database.connections.sqlite.database' => $file, 'app.key' => $this->key]);
        $this->app['db']->purge();

        Artisan::call('migrate', ['--force' => true]);
        $this->seedRoles();
        $this->owner = $this->owner();
        $installer->markInstalled();
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        $this->tearDownInstallation();

        parent::tearDown();
    }

    private function reset(string $confirmation = 'FACTORY RESET', string $password = 'password', ?User $actor = null): string
    {
        return $this->app->make(FactoryReset::class)->handle($actor ?? $this->owner, $confirmation, $password);
    }

    public function test_it_erases_everything_and_goes_back_to_the_installer_state(): void
    {
        Store::factory()->create();
        Storage::disk('local')->put('avatars/me.png', 'png');
        $env = (string) file_get_contents($this->envFile);
        $email = $this->owner->email;
        $id = $this->owner->id;
        Log::spy();

        $backup = $this->reset();

        foreach (['users', 'stores', 'roles', 'activity_entries', 'app_settings'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} must be recreated");
            $this->assertSame(0, DB::table($table)->count(), "{$table} must be empty");
        }
        $this->assertFileDoesNotExist($this->installDirectory.'/installed');
        Storage::disk('local')->assertMissing('avatars/me.png');
        $this->assertDirectoryExists(Storage::disk('local')->path('avatars'));

        // `.env`, clé et sauvegardes intacts.
        $this->assertSame($env, (string) file_get_contents($this->envFile));
        $this->assertSame($this->key, config('app.key'));
        $this->assertNotNull($this->backups()->find($backup));
        $this->assertSame('reset', $this->backups()->find($backup)->trigger);

        Log::shouldHaveReceived('notice')->once()->withArgs(fn (string $message, array $context): bool => $context['backup'] === $backup
            && $context['actor']['email'] === $email
            && $context['actor']['id'] === $id);
    }

    public function test_it_requires_the_owner_the_phrase_and_the_password(): void
    {
        $store = Store::factory()->create();
        $manager = $this->managerOf($store);

        foreach (['', 'factory reset', 'RESET', 'FACTORY RESET '] as $text) {
            try {
                $this->reset($text);
                $this->fail('A wrong confirmation text must be refused.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('confirmation', $e->errors());
            }
        }

        foreach (['', 'wrong'] as $password) {
            try {
                $this->reset('FACTORY RESET', $password);
                $this->fail('A wrong password must be refused.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('password', $e->errors());
            }
        }

        try {
            $this->reset(actor: $manager);
            $this->fail('Only the Owner can reset.');
        } catch (AuthorizationException) {
        }

        $this->assertSame(1, Store::query()->count());
        $this->assertFileExists($this->installDirectory.'/installed');
        $this->assertSame([], $this->backups()->all(), 'no backup is made for a refused reset');
    }

    public function test_nothing_is_erased_when_the_backup_fails(): void
    {
        Store::factory()->create();
        Storage::disk('local')->put('avatars/me.png', 'png');
        $this->app->bind(BackupManager::class, fn () => new class extends BackupManager
        {
            public function create(?string $note = null, string $trigger = self::TRIGGER_MANUAL): BackupInfo
            {
                throw new BackupException('The backups folder cannot be written.');
            }
        });

        try {
            $this->reset();
            $this->fail('The reset must stop when the backup fails.');
        } catch (BackupException) {
        }

        $this->assertSame(1, Store::query()->count());
        $this->assertSame(1, User::query()->count());
        $this->assertFileExists($this->installDirectory.'/installed');
        Storage::disk('local')->assertExists('avatars/me.png');
    }

    public function test_the_installer_is_served_and_can_install_again_with_the_existing_env(): void
    {
        config(['kintai.install.enforce' => true]);
        $this->reset();

        $this->get('/install')->assertOk();
        $this->get('/login')->assertRedirect(route('install.show'));

        $owner = $this->app->make(Installer::class)->install($this->ownerInput());

        $this->assertSame('owner@example.test', $owner->email);
        $this->assertTrue($owner->fresh()->roles->contains('slug', 'owner'));
        $this->assertFileExists($this->installDirectory.'/installed');
        $this->assertStringContainsString('mine.sqlite', (string) file_get_contents($this->envFile));
        $this->assertSame($this->key, config('app.key'));
    }

    public function test_the_screen_signs_the_owner_out_and_sends_them_to_the_installer(): void
    {
        Livewire::test('pages::admin.data-reset')
            ->assertSee('Factory reset')
            ->call('confirmFactoryReset')
            ->set('factory_confirmation', 'RESET')
            ->set('factory_password', 'password')
            ->call('executeFactoryReset')
            ->assertHasErrors('confirmation');
        $this->assertSame(1, User::query()->count());

        Livewire::test('pages::admin.data-reset')
            ->set('factory_confirmation', 'FACTORY RESET')
            ->set('factory_password', 'bad')
            ->call('executeFactoryReset')
            ->assertHasErrors('password');
        $this->assertSame(1, User::query()->count());

        Livewire::test('pages::admin.data-reset')
            ->set('factory_confirmation', 'FACTORY RESET')
            ->set('factory_password', 'password')
            ->call('executeFactoryReset')
            ->assertRedirect(route('install.show'));

        $this->assertSame(0, User::query()->count());
        $this->assertGuest();
        $this->assertFileDoesNotExist($this->installDirectory.'/installed');
    }

    public function test_the_command_needs_the_phrase_or_force(): void
    {
        Store::factory()->create();

        $this->artisan('kintai:factory-reset')->expectsQuestion('Type FACTORY RESET to confirm', 'no')->assertFailed();
        $this->assertSame(1, Store::query()->count());
        $this->assertSame([], $this->backups()->all());

        $this->artisan('kintai:factory-reset')->expectsQuestion('Type FACTORY RESET to confirm', 'FACTORY RESET')->assertSuccessful();
        $this->assertSame(0, User::query()->count());
        $this->assertCount(1, $this->backups()->all());
    }

    public function test_the_command_with_force_does_not_ask(): void
    {
        $this->artisan('kintai:factory-reset', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, User::query()->count());
        $this->assertFileDoesNotExist($this->installDirectory.'/installed');
    }

    public function test_it_releases_the_license_seat_before_erasing_and_never_fails_on_the_network(): void
    {
        config(['kintai.license.api_url' => 'https://lic.test/api/v1', 'kintai.license.api_key' => 'product-key']);
        $settings = $this->app->make(AppSettings::class);
        $settings->set('license.key', 'SECRET-LICENSE-KEY');

        Http::preventStrayRequests();
        Http::fake(['https://lic.test/api/v1/deactivate' => Http::response(['valid' => true])]);
        $this->reset();

        Http::assertSent(fn ($request): bool => $request->url() === 'https://lic.test/api/v1/deactivate' && $request['license_key'] === 'SECRET-LICENSE-KEY');
        $this->assertSame(0, DB::table('app_settings')->count());
    }

    public function test_an_unreachable_license_server_does_not_stop_the_reset(): void
    {
        config(['kintai.license.api_url' => 'https://lic.test/api/v1', 'kintai.license.api_key' => 'product-key']);
        $this->app->make(AppSettings::class)->set('license.key', 'SECRET-LICENSE-KEY');
        Http::preventStrayRequests();
        Http::fake(['https://lic.test/api/v1/deactivate' => fn () => throw new ConnectionException('down')]);

        $this->reset();

        $this->assertSame(0, User::query()->count());
    }

    public function test_without_a_license_no_request_is_made(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $this->reset();

        Http::assertNothingSent();
    }
}
