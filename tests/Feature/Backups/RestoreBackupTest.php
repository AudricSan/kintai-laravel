<?php

namespace Tests\Feature\Backups;

use App\Actions\Backups\RestoreBackup;
use App\Backups\BackupException;
use App\Backups\BackupInfo;
use App\Backups\BackupManager;
use App\Backups\BackupRestorer;
use App\Models\ActivityEntry;
use App\Models\Store;
use App\Models\User;
use App\Settings\AppSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAccess;
use Tests\Concerns\UsesBackupDirectory;
use Tests\TestCase;

class RestoreBackupTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;
    use UsesBackupDirectory;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBackupDirectory();
        $this->seedRoles();
        $this->owner = $this->owner();
        $this->actingAs($this->owner);
    }

    private function restore(string $id, string $password = 'password', ?User $actor = null): array
    {
        return $this->app->make(RestoreBackup::class)->handle($actor ?? $this->owner, $id, $password);
    }

    private function settings(): AppSettings
    {
        return $this->app->make(AppSettings::class);
    }

    public function test_the_data_comes_back_and_a_safety_backup_is_made(): void
    {
        $kept = Store::factory()->create(['name' => 'Kept']);
        $backup = $this->backups()->create('before');
        $kept->update(['name' => 'Renamed']);
        $later = Store::factory()->create(['name' => 'Created later']);

        $result = $this->restore($backup->id);

        $this->assertSame('Kept', Store::query()->findOrFail($kept->id)->name);
        $this->assertNull(Store::query()->find($later->id));
        $this->assertFalse($result['migrated']);

        // La sauvegarde de sécurité contient l'état d'avant la restauration.
        $safety = $this->backups()->find($result['safety']);
        $this->assertNotNull($safety);
        $this->assertSame('safety', $safety->trigger);
        $this->assertStringContainsString($backup->id, $safety->note);
        $this->assertCount(2, $this->backups()->all());

        $entry = ActivityEntry::query()->where('action', 'backup.restored')->latest('id')->firstOrFail();
        $this->assertSame($this->owner->id, $entry->actor_id);
        $this->assertSame($backup->id, $entry->properties['backup']);
        $this->assertSame($safety->id, $entry->properties['safety_backup']);
    }

    public function test_the_safety_backup_can_undo_the_restoration(): void
    {
        $backup = $this->backups()->create();
        $later = Store::factory()->create(['name' => 'Created later']);

        $result = $this->restore($backup->id);
        $this->assertNull(Store::query()->find($later->id));

        $this->restore($result['safety']);
        $this->assertSame('Created later', Store::query()->findOrFail($later->id)->name);
    }

    public function test_maintenance_is_on_during_the_restoration_then_put_back(): void
    {
        $backup = $this->backups()->create();
        $manager = $this->backups();
        $seen = new \ArrayObject;

        $this->app->bind(BackupRestorer::class, fn () => new class($manager, $seen) extends BackupRestorer
        {
            public function __construct(BackupManager $manager, private \ArrayObject $seen)
            {
                parent::__construct($manager);
            }

            public function restore(BackupInfo $backup): void
            {
                $this->seen->append(app(AppSettings::class)->maintenanceEnabled());
                parent::restore($backup);
            }
        });

        $this->assertFalse($this->settings()->maintenanceEnabled());
        $this->restore($backup->id);

        $this->assertSame([true], $seen->getArrayCopy(), 'maintenance was on while the data was replaced');
        $this->assertFalse($this->settings()->maintenanceEnabled(), 'previous state (off) put back');

        // Déjà en maintenance avant : elle le reste.
        $this->settings()->set('maintenance.enabled', true);
        $this->restore($this->backups()->create()->id);
        $this->assertTrue($this->settings()->maintenanceEnabled());
    }

    public function test_the_settings_cache_is_not_stale_after_the_restoration(): void
    {
        $this->settings()->set('organization.subtitle', 'Before');
        $backup = $this->backups()->create();
        $this->settings()->set('organization.subtitle', 'After');
        $this->assertSame('After', $this->settings()->subtitle());

        $this->restore($backup->id);

        $this->assertSame('Before', $this->settings()->subtitle());
    }

    public function test_uploaded_files_are_restored_and_later_ones_removed(): void
    {
        Storage::disk('local')->put('avatars/user_1_aaaaaaaaaaaaaaaa.png', 'ORIGINAL');
        $backup = $this->backups()->create();

        Storage::disk('local')->put('avatars/user_1_aaaaaaaaaaaaaaaa.png', 'CHANGED');
        Storage::disk('local')->put('avatars/user_2_bbbbbbbbbbbbbbbb.png', 'LATER');

        $this->restore($backup->id);

        $this->assertSame('ORIGINAL', Storage::disk('local')->get('avatars/user_1_aaaaaaaaaaaaaaaa.png'));
        Storage::disk('local')->assertMissing('avatars/user_2_bbbbbbbbbbbbbbbb.png');
    }

    public function test_migrations_run_when_the_backup_is_older_than_the_schema(): void
    {
        $id = $this->forgeArchive(tamperDatabase: function (PDO $pdo): void {
            $pdo->exec("DELETE FROM migrations WHERE migration = '2026_11_07_000001_create_app_settings_table'");
            $pdo->exec('DROP TABLE app_settings');
        });

        $result = $this->restore($id);

        $this->assertTrue($result['migrated']);
        $this->assertTrue(Schema::hasTable('app_settings'));
        $this->assertDatabaseHas('migrations', ['migration' => '2026_11_07_000001_create_app_settings_table']);
        $this->assertDatabaseHas('users', ['id' => $this->owner->id]);
    }

    public function test_a_failed_restoration_puts_the_application_back_and_names_the_safety_backup(): void
    {
        $backup = $this->backups()->create();
        $store = Store::factory()->create(['name' => 'Present before']);
        $manager = $this->backups();

        // La première restauration (celle demandée) casse la base puis échoue ; la seconde (la sécurité) est réelle.
        $this->app->bind(BackupRestorer::class, fn () => new class($manager) extends BackupRestorer
        {
            private int $calls = 0;

            public function restore(BackupInfo $backup): void
            {
                if (++$this->calls === 1) {
                    DB::table('stores')->delete();

                    throw new BackupException('boom');
                }

                parent::restore($backup);
            }
        });

        try {
            $this->restore($backup->id);
            $this->fail('The restoration should have failed.');
        } catch (BackupException $e) {
            $safety = collect($this->backups()->all())->first(fn ($b) => $b->trigger === 'safety');
            $this->assertNotNull($safety);
            $this->assertStringContainsString($safety->id, $e->getMessage());
            $this->assertStringContainsString('put back in its previous state', $e->getMessage());
            $this->assertStringNotContainsString('boom', $e->getMessage());
        }

        $this->assertSame('Present before', Store::query()->findOrFail($store->id)->name);
        $this->assertFalse($this->settings()->maintenanceEnabled());
    }

    public function test_an_unrecoverable_failure_still_points_to_the_safety_backup(): void
    {
        $backup = $this->backups()->create();
        $manager = $this->backups();

        $this->app->bind(BackupRestorer::class, fn () => new class($manager) extends BackupRestorer
        {
            public function restore(BackupInfo $backup): void
            {
                throw new BackupException('boom');
            }
        });

        try {
            $this->restore($backup->id);
            $this->fail('The restoration should have failed.');
        } catch (BackupException $e) {
            $safety = collect($this->backups()->all())->first(fn ($b) => $b->trigger === 'safety');
            $this->assertStringContainsString('Restore this safety backup: '.$safety->id, $e->getMessage());
        }

        $this->assertFalse($this->settings()->maintenanceEnabled());
    }

    public function test_the_password_is_required_and_checked(): void
    {
        $backup = $this->backups()->create();
        Store::factory()->create();

        foreach (['', 'wrong'] as $password) {
            try {
                $this->restore($backup->id, $password);
                $this->fail('A wrong password must be refused.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('password', $e->errors());
            }
        }

        $this->assertSame(1, Store::query()->count(), 'nothing was restored');
        $this->assertCount(1, $this->backups()->all(), 'no safety backup either');
    }

    public function test_repeated_wrong_passwords_are_throttled(): void
    {
        $backup = $this->backups()->create();

        for ($i = 0; $i < 5; $i++) {
            try {
                $this->restore($backup->id, 'wrong');
            } catch (ValidationException) {
            }
        }

        try {
            $this->restore($backup->id, 'password');
            $this->fail('The sixth attempt must be throttled.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Too many attempts', $e->errors()['password'][0]);
        }
    }

    public function test_only_the_owner_can_restore(): void
    {
        $backup = $this->backups()->create();
        $store = Store::factory()->create();
        $manager = $this->managerOf($store);

        $this->expectException(AuthorizationException::class);
        $this->restore($backup->id, 'password', $manager);
    }

    public function test_an_unknown_backup_is_refused(): void
    {
        try {
            $this->restore('backup_20260101_000000_00000000.zip');
            $this->fail('An unknown backup must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('backup', $e->errors());
        }

        try {
            $this->restore('../../.env');
            $this->fail('A path must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('backup', $e->errors());
        }
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: array<string, mixed>|null}>
     */
    public static function invalidArchives(): array
    {
        return [
            'no manifest' => [[], null],
            'wrong format' => [[], ['format' => 99]],
            'unknown driver' => [[], ['driver' => 'oracle']],
            'database file does not match the driver' => [[], ['driver' => 'mysql']],
            'database file missing' => [[], ['database_file' => 'database.sql']],
            'zip-slip entry' => [['../evil.txt' => 'x'], []],
            'zip-slip in avatars' => [['files/avatars/../../evil.png' => 'x'], []],
            'absolute path' => [['/etc/passwd' => 'x'], []],
            'windows path' => [['files\\avatars\\x.png' => 'x'], []],
            'unexpected entry' => [['storage/app/.env' => 'x'], []],
            'unexpected extension' => [['files/avatars/shell.php' => '<?php'], []],
            'hidden file' => [['files/avatars/.htaccess' => 'x'], []],
            'two databases' => [['database.sql' => 'x', 'database.sqlite' => 'y'], []],
            'nested folder' => [['files/avatars/sub/x.png' => 'x'], []],
        ];
    }

    /**
     * @param  array<string, string>  $entries
     * @param  array<string, mixed>|null  $manifest
     */
    #[DataProvider('invalidArchives')]
    public function test_an_invalid_archive_is_refused_before_anything_changes(array $entries, ?array $manifest): void
    {
        $store = Store::factory()->create(['name' => 'Untouched']);
        $id = $this->forgeArchive($entries, $manifest);

        try {
            $this->restore($id);
            $this->fail('This archive must be refused.');
        } catch (BackupException) {
            // attendu
        }

        $this->assertSame('Untouched', Store::query()->findOrFail($store->id)->name);
        $this->assertCount(1, $this->backups()->all(), 'no safety backup for a refused archive');
        $this->assertFileDoesNotExist(base_path('evil.txt'));
        $this->assertFileDoesNotExist($this->backupDir.'/../evil.txt');
        $this->assertFalse($this->settings()->maintenanceEnabled());
    }

    public function test_an_archive_from_another_driver_is_refused_with_a_clear_message(): void
    {
        $id = $this->forgeArchive(['database.sql' => 'SELECT 1;'], ['driver' => 'mysql', 'database_file' => 'database.sql']);

        try {
            $this->restore($id);
            $this->fail('A MySQL backup must not be restored on SQLite.');
        } catch (BackupException $e) {
            $this->assertStringContainsString('another database type (mysql)', $e->getMessage());
        }

        $this->assertCount(1, $this->backups()->all(), 'no safety backup for a refused archive');
    }

    public function test_an_archive_that_is_too_large_once_unpacked_is_refused(): void
    {
        config(['kintai.backups.max_uncompressed_bytes' => 100]);
        $id = $this->forgeArchive(['files/avatars/big.png' => str_repeat('A', 5000)]);

        try {
            $this->restore($id);
            $this->fail('An oversized archive must be refused.');
        } catch (BackupException $e) {
            $this->assertStringContainsString('too large', $e->getMessage());
        }
    }

    public function test_a_damaged_database_is_refused_and_leaves_the_data_untouched(): void
    {
        $store = Store::factory()->create(['name' => 'Untouched']);
        $id = $this->forgeArchive(['database.sqlite' => 'this is not a sqlite database']);

        try {
            $this->restore($id);
            $this->fail('A damaged database must be refused.');
        } catch (BackupException $e) {
            // Rien de technique dans le message montré.
            $this->assertStringNotContainsString('SQLSTATE', $e->getMessage());
            $this->assertStringNotContainsString($this->backupDir, $e->getMessage());
        }

        $this->assertSame('Untouched', Store::query()->findOrFail($store->id)->name);
        $this->assertFalse($this->settings()->maintenanceEnabled());
    }

    public function test_files_are_never_written_outside_the_avatars_folder(): void
    {
        $outside = Storage::disk('local')->path('escaped.png');
        $id = $this->forgeArchive(['files/avatars/ok.png' => 'OK']);

        $this->restore($id);

        Storage::disk('local')->assertExists('avatars/ok.png');
        $this->assertFileDoesNotExist($outside);
    }
}
