<?php

namespace Tests\Feature\Update;

use App\Backups\BackupInfo;
use App\Backups\BackupManager;
use App\Backups\BackupRestorer;
use App\Settings\AppSettings;
use App\Update\MigrationRunner;
use App\Update\Release;
use App\Update\ReleaseDownloader;
use App\Update\UpdateApplier;
use App\Update\UpdateException;
use App\Update\UpdatePaths;
use App\Update\UpdateState;
use App\Update\Version;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

/**
 * Le moteur d'application d'une mise à jour, dans un faux dossier d'application temporaire (jamais le vrai dépôt).
 */
class UpdateApplierTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{0: ?string, 1: string}> */
    public array $backupCalls = [];

    public int $restoreCalls = 0;

    public int $migrationRuns = 0;

    public int $cacheClears = 0;

    public ?string $migrationFailure = null;

    private string $root;

    private string $temp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'kintai-upd-'.bin2hex(random_bytes(4));
        $this->root = $this->temp.DIRECTORY_SEPARATOR.'app';
        $this->beforeApplicationDestroyed(fn () => BackupManager::removeDirectory($this->temp));

        foreach ([
            'artisan' => 'old artisan',
            'composer.json' => '{}',
            'vendor/autoload.php' => 'old autoload',
            'app/Old.php' => 'old',
            'app/Gone.php' => 'gone',
            'config/kintai.php' => 'old config',
            'bootstrap/cache/packages.php' => 'cache',
            'bootstrap/cache/.gitignore' => '*',
            '.env' => 'APP_KEY=secret',
            'storage/x' => 'storage file',
            'public/storage/keep.txt' => 'keep',
        ] as $path => $contents) {
            $this->writeFile($this->root.'/'.$path, $contents);
        }

        config(['kintai.version' => '1.0.0']);
    }

    // -- Aides -------------------------------------------------------------------------------------------------

    private function writeFile(string $path, string $contents): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, $contents);
    }

    private function read(string $relative): ?string
    {
        $path = $this->root.'/'.$relative;

        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    private function settings(): AppSettings
    {
        return $this->app->make(AppSettings::class);
    }

    /**
     * @param  array<string, string>  $extra
     * @param  list<string>  $without
     */
    private function zip(string $version = '1.1.0', array $extra = [], array $manifest = [], array $without = []): string
    {
        $entries = [
            'kintai-release.json' => (string) json_encode($manifest + ['version' => $version, 'min_php' => '8.3']),
            'artisan' => 'new artisan',
            'composer.json' => '{"new":true}',
            'vendor/autoload.php' => 'new autoload',
            'app/Old.php' => 'new old',
            'app/New.php' => 'new file',
            'config/kintai.php' => 'new config',
            'bootstrap/app.php' => 'new bootstrap',
            'public/storage/evil.txt' => 'evil',
        ];

        $path = $this->temp.DIRECTORY_SEPARATOR.'release-'.bin2hex(random_bytes(3)).'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);

        foreach (array_diff_key($extra + $entries, array_flip($without)) as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return $path;
    }

    private function release(string $version = '1.1.0'): Release
    {
        return new Release(Version::parse($version), 'v'.$version, '', 'https://example.test/a.zip', 1, 'https://example.test/a.sha256', null);
    }

    private function applier(?string $zip = null, ?Closure $freeSpace = null, ?Closure $writable = null): UpdateApplier
    {
        $test = $this;
        $zip ??= $this->zip();

        $downloader = new class($zip) extends ReleaseDownloader
        {
            public function __construct(private readonly string $zip) {}

            public function download(Release $release, string $directory): string
            {
                $destination = $directory.DIRECTORY_SEPARATOR.'kintai-'.$release->version.'.zip';
                copy($this->zip, $destination);

                return $destination;
            }
        };

        $info = new BackupInfo('backup_20260101_000000_aaaaaaaa.zip', 'x', 1, CarbonImmutable::now(), '', 'sqlite', '1.0.0', BackupManager::TRIGGER_UPDATE, true);

        $backups = new class($test, $info) extends BackupManager
        {
            public function __construct(private readonly UpdateApplierTest $test, private readonly BackupInfo $info) {}

            public function create(?string $note = null, string $trigger = self::TRIGGER_MANUAL): BackupInfo
            {
                $this->test->backupCalls[] = [$note, $trigger];

                return $this->info;
            }

            public function find(string $id): ?BackupInfo
            {
                return $id === $this->info->id ? $this->info : null;
            }
        };

        $restorer = new class($test, $backups) extends BackupRestorer
        {
            public function __construct(private readonly UpdateApplierTest $test, BackupManager $manager)
            {
                parent::__construct($manager);
            }

            public function restore(BackupInfo $backup): void
            {
                $this->test->restoreCalls++;
            }
        };

        $runner = new class($test) extends MigrationRunner
        {
            public function __construct(private readonly UpdateApplierTest $test) {}

            public function run(): string
            {
                $this->test->migrationRuns++;

                if ($this->test->migrationFailure !== null) {
                    throw new UpdateException($this->test->migrationFailure);
                }

                return 'Migrated.';
            }

            public function clearCaches(): void
            {
                $this->test->cacheClears++;
            }
        };

        return new UpdateApplier(new UpdatePaths($this->root), $downloader, $backups, $restorer, $this->settings(), $runner, $freeSpace, $writable);
    }

    private function state(): UpdateState
    {
        return UpdateState::load($this->root.'/storage/app/updates/state.json');
    }

    private function stagedAndApplied(?UpdateApplier $applier = null): UpdateApplier
    {
        $applier ??= $this->applier();
        $applier->stage($this->release());
        $applier->apply();

        return $applier;
    }

    /** @return resource */
    private function holdLock()
    {
        mkdir($this->root.'/storage/app/updates', 0775, true);
        $handle = fopen($this->root.'/storage/app/updates/.lock', 'c');
        $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB));

        return $handle;
    }

    private function assertApplicationUntouched(): void
    {
        $this->assertSame('old artisan', $this->read('artisan'));
        $this->assertSame('old autoload', $this->read('vendor/autoload.php'));
        $this->assertSame('old', $this->read('app/Old.php'));
        $this->assertSame('gone', $this->read('app/Gone.php'));
        $this->assertSame('old config', $this->read('config/kintai.php'));
        $this->assertNull($this->read('app/New.php'));
        $this->assertNull($this->read('bootstrap/app.php'));
    }

    // -- stage -------------------------------------------------------------------------------------------------

    public function test_stage_extracts_the_release_without_touching_the_application(): void
    {
        $result = $this->applier()->stage($this->release());

        $this->assertSame('1.0.0', $result['from']);
        $this->assertSame('1.1.0', $result['to']);
        $this->assertSame(8, $result['files']);
        $this->assertGreaterThan(0, $result['bytes']);

        $state = $this->state();
        $this->assertSame(UpdateState::STAGED, $state->phase);
        $this->assertSame(['1.0.0', '1.1.0'], [$state->from, $state->to]);
        $this->assertSame('new old', file_get_contents($this->root.'/storage/app/updates/work/1.1.0/app/Old.php'));
        $this->assertSame([], glob($this->root.'/storage/app/updates/*.zip'), 'The zip must be deleted.');
        $this->assertApplicationUntouched();
    }

    public function test_stage_refuses_a_version_that_is_not_newer(): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('not newer');

        $this->applier($this->zip('1.0.0'))->stage($this->release('1.0.0'));
    }

    public function test_stage_accepts_a_downgrade_when_allowed(): void
    {
        $result = $this->applier($this->zip('0.9.0'))->stage($this->release('0.9.0'), true);

        $this->assertSame('0.9.0', $result['to']);
    }

    public function test_stage_refuses_when_php_is_too_old(): void
    {
        $applier = $this->applier($this->zip('1.1.0', [], ['min_php' => '99.0']));

        try {
            $applier->stage($this->release());
            $this->fail('Expected an UpdateException.');
        } catch (UpdateException $e) {
            $this->assertStringContainsString('PHP 99.0', $e->getMessage());
        }

        $this->assertSame(UpdateState::IDLE, $this->state()->phase);
        $this->assertDirectoryDoesNotExist($this->root.'/storage/app/updates/work/1.1.0');
    }

    public function test_stage_refuses_when_the_installed_version_is_too_old_for_the_release(): void
    {
        $applier = $this->applier($this->zip('1.1.0', [], ['min_from_version' => '1.0.5']));

        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('1.0.5');

        $applier->stage($this->release());
    }

    public function test_stage_refuses_when_a_target_folder_is_not_writable(): void
    {
        $applier = $this->applier(null, null, fn (string $path): bool => ! str_ends_with(str_replace('\\', '/', $path), '/config'));

        try {
            $applier->stage($this->release());
            $this->fail('Expected an UpdateException.');
        } catch (UpdateException $e) {
            $this->assertStringContainsString('config', $e->getMessage());
        }

        $this->assertSame(UpdateState::IDLE, $this->state()->phase);
        $this->assertDirectoryDoesNotExist($this->root.'/storage/app/updates/work/1.1.0');
    }

    public function test_stage_refuses_when_the_disk_space_is_insufficient(): void
    {
        $applier = $this->applier(null, fn (string $directory): float => 10.0);

        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('free disk space');

        $applier->stage($this->release());
    }

    public function test_stage_is_refused_while_an_update_is_unfinished(): void
    {
        $applier = $this->stagedAndApplied();

        $this->assertSame(UpdateState::SWAPPED, $this->state()->phase);

        try {
            $applier->stage($this->release('1.2.0'));
            $this->fail('Expected an UpdateException.');
        } catch (UpdateException $e) {
            $this->assertStringContainsString('already in progress', $e->getMessage());
        }

        $this->assertSame('1.1.0', $this->state()->to);
    }

    public function test_stage_is_refused_when_a_prepared_update_is_waiting(): void
    {
        $applier = $this->applier();
        $applier->stage($this->release());

        $this->expectException(UpdateException::class);

        $applier->stage($this->release());
    }

    public function test_a_second_operation_is_refused_while_the_lock_is_taken(): void
    {
        $handle = $this->holdLock();

        try {
            $this->applier()->stage($this->release());
            $this->fail('Expected an UpdateException.');
        } catch (UpdateException $e) {
            $this->assertSame('Another update is already running.', $e->getMessage());
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        // Le verrou libéré, l'opération passe : il n'est pas resté pris.
        $this->assertSame('1.1.0', $this->applier()->stage($this->release())['to']);
    }

    public function test_the_lock_is_released_after_a_failure(): void
    {
        $applier = $this->applier();

        try {
            $applier->apply();
        } catch (UpdateException) {
        }

        $handle = fopen($this->root.'/storage/app/updates/.lock', 'c');
        $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB));
        fclose($handle);
    }

    public function test_cancel_drops_a_prepared_update(): void
    {
        $applier = $this->applier();
        $applier->stage($this->release());
        $applier->cancel();

        $this->assertSame(UpdateState::IDLE, $this->state()->phase);
        $this->assertDirectoryDoesNotExist($this->root.'/storage/app/updates/work');
        $this->assertSame('1.1.0', $applier->stage($this->release())['to']);
    }

    // -- apply -------------------------------------------------------------------------------------------------

    public function test_apply_replaces_creates_and_removes_files_and_leaves_protected_paths_alone(): void
    {
        // Mise à jour précédente : a posé app/Gone.php ; le .env et storage/x y figurent à tort (fichier trafiqué).
        $this->writeFile($this->root.'/storage/app/updates/installed-files.json', (string) json_encode(['app/Old.php', 'app/Gone.php', '.env', 'storage/x', '../escape.txt', 'public/storage/keep.txt', 'database/x.sqlite']));
        $this->writeFile($this->root.'/database/x.sqlite', 'db');
        $this->writeFile($this->temp.'/escape.txt', 'outside');
        $this->settings()->setMany(['maintenance.enabled' => false, 'maintenance.message' => '']);

        $this->stagedAndApplied();

        $this->assertSame('new artisan', $this->read('artisan'));
        $this->assertSame('new autoload', $this->read('vendor/autoload.php'));
        $this->assertSame('new old', $this->read('app/Old.php'));
        $this->assertSame('new file', $this->read('app/New.php'));
        $this->assertSame('new config', $this->read('config/kintai.php'));
        $this->assertSame('new bootstrap', $this->read('bootstrap/app.php'));
        $this->assertNull($this->read('app/Gone.php'));

        // Protégés intacts.
        $this->assertSame('APP_KEY=secret', $this->read('.env'));
        $this->assertSame('storage file', $this->read('storage/x'));
        $this->assertSame('keep', $this->read('public/storage/keep.txt'));
        $this->assertNull($this->read('public/storage/evil.txt'));
        $this->assertSame('db', $this->read('database/x.sqlite'));
        $this->assertSame('outside', file_get_contents($this->temp.'/escape.txt'));

        // Cache d'amorçage vidé, .gitignore conservé.
        $this->assertNull($this->read('bootstrap/cache/packages.php'));
        $this->assertSame('*', $this->read('bootstrap/cache/.gitignore'));

        // Aucun fichier temporaire laissé.
        $this->assertSame([], glob($this->root.'/app/.kintai-new-*'));

        // Sauvegarde, maintenance, état.
        $this->assertCount(1, $this->backupCalls);
        $this->assertSame(BackupManager::TRIGGER_UPDATE, $this->backupCalls[0][1]);
        $this->assertTrue($this->settings()->maintenanceEnabled());
        $this->assertNotSame('', $this->settings()->maintenanceMessage());

        $state = $this->state();
        $this->assertSame(UpdateState::SWAPPED, $state->phase);
        $this->assertTrue($state->swapComplete);
        $this->assertSame('backup_20260101_000000_aaaaaaaa.zip', $state->backupId);
        $this->assertSame(['enabled' => false, 'message' => ''], $state->maintenanceBefore);
        $this->assertContains('app/Old.php', $state->replaced);
        $this->assertContains('app/New.php', $state->created);
        $this->assertSame(['app/Gone.php'], $state->deleted);
        $this->assertNotContains('.env', array_merge($state->replaced, $state->created, $state->deleted));
        $this->assertSame('old', file_get_contents($this->root.'/storage/app/updates/previous/1.1.0/app/Old.php'));
        $this->assertSame('gone', file_get_contents($this->root.'/storage/app/updates/previous/1.1.0/app/Gone.php'));

        $installed = json_decode((string) $this->read('storage/app/updates/installed-files.json'), true);
        $this->assertContains('app/New.php', $installed);
        $this->assertNotContains('app/Gone.php', $installed);
    }

    public function test_apply_replaces_the_bootstrap_files_last(): void
    {
        $this->stagedAndApplied();

        $order = $this->state()->replaced;
        $position = fn (string $path) => array_search($path, $order, true);

        $this->assertLessThan($position('app/Old.php'), $position('vendor/autoload.php'));

        foreach (['config/kintai.php', 'artisan', 'composer.json'] as $last) {
            $this->assertGreaterThan($position('app/Old.php'), $position($last));
            $this->assertGreaterThan($position('vendor/autoload.php'), $position($last));
        }
    }

    public function test_apply_without_a_previous_file_list_removes_nothing(): void
    {
        $this->stagedAndApplied();

        $this->assertSame('gone', $this->read('app/Gone.php'));
        $this->assertSame([], $this->state()->deleted);
    }

    public function test_apply_requires_a_prepared_update(): void
    {
        $this->expectException(UpdateException::class);

        $this->applier()->apply();
    }

    public function test_apply_does_nothing_when_the_backup_fails(): void
    {
        $applier = $this->applier();
        $applier->stage($this->release());
        $this->settings()->setMany(['maintenance.enabled' => false, 'maintenance.message' => '']);

        $broken = new UpdateApplier(
            new UpdatePaths($this->root),
            new ReleaseDownloader,
            new class extends BackupManager
            {
                public function __construct() {}

                public function create(?string $note = null, string $trigger = self::TRIGGER_MANUAL): BackupInfo
                {
                    throw new \RuntimeException('disk full');
                }
            },
            new class(new BackupManager) extends BackupRestorer {},
            $this->settings(),
            new MigrationRunner,
        );

        try {
            $broken->apply();
            $this->fail('Expected an UpdateException.');
        } catch (UpdateException $e) {
            $this->assertStringContainsString('disk full', $e->getMessage());
        }

        $this->assertApplicationUntouched();
        $this->assertFalse($this->settings()->maintenanceEnabled());
        $this->assertSame(UpdateState::STAGED, $this->state()->phase);
    }

    public function test_a_failure_in_the_middle_of_apply_rolls_everything_back(): void
    {
        $this->writeFile($this->root.'/storage/app/updates/installed-files.json', (string) json_encode(['app/Gone.php']));
        $this->settings()->setMany(['maintenance.enabled' => true, 'maintenance.message' => 'Owner message']);

        $applier = $this->applier($this->zip('1.1.0', ['config/blocked/file.php' => 'x']));
        $applier->stage($this->release());
        // Le dossier « config/blocked » ne peut pas être créé : c'est un fichier. Les fichiers de rang inférieur sont déjà remplacés.
        $this->writeFile($this->root.'/config/blocked', 'i am a file');

        try {
            $applier->apply();
            $this->fail('Expected an UpdateException.');
        } catch (UpdateException $e) {
            $this->assertStringContainsString('config/blocked', str_replace('\\', '/', $e->getMessage()));
        }

        $this->assertApplicationUntouched();
        $this->assertSame('i am a file', $this->read('config/blocked'));
        $this->assertSame('*', $this->read('bootstrap/cache/.gitignore'));
        $this->assertSame('APP_KEY=secret', $this->read('.env'));
        $this->assertSame([], glob($this->root.'/app/.kintai-new-*'));

        $this->assertTrue($this->settings()->maintenanceEnabled(), 'Maintenance was already on before: it stays on.');
        $this->assertSame('Owner message', $this->settings()->maintenanceMessage());

        $state = $this->state();
        $this->assertSame(UpdateState::ROLLED_BACK, $state->phase);
        $this->assertNotSame([], $state->error);
        $this->assertSame(0, $this->restoreCalls, 'The database is untouched before the migrations.');
        $this->assertDirectoryDoesNotExist($this->root.'/storage/app/updates/previous');
        $this->assertFileDoesNotExist($this->root.'/storage/app/updates/installed-files.json.tmp');
    }

    public function test_a_failed_apply_restores_the_maintenance_that_was_off(): void
    {
        $this->settings()->setMany(['maintenance.enabled' => false, 'maintenance.message' => '']);
        $applier = $this->applier($this->zip('1.1.0', ['config/blocked/file.php' => 'x']));
        $applier->stage($this->release());
        $this->writeFile($this->root.'/config/blocked', 'file');

        try {
            $applier->apply();
        } catch (UpdateException) {
        }

        $this->assertFalse($this->settings()->maintenanceEnabled());
        $this->assertSame('', $this->settings()->maintenanceMessage());
    }

    public function test_apply_refuses_to_write_through_a_symlinked_folder(): void
    {
        $outside = $this->temp.'/outside';
        mkdir($outside);
        $applier = $this->applier($this->zip('1.1.0', ['app/Linked/evil.php' => 'evil']));
        $applier->stage($this->release());

        if (! $this->makeSymlink($outside, $this->root.'/app/Linked')) {
            $this->markTestSkipped('Symbolic links are not available on this machine.');
        }

        try {
            $applier->apply();
            $this->fail('Expected an UpdateException.');
        } catch (UpdateException $e) {
            $this->assertStringContainsString('app/Linked/evil.php', str_replace('\\', '/', $e->getMessage()));
        } finally {
            $this->removeLink($this->root.'/app/Linked');
        }

        $this->assertSame([], glob($outside.'/*'));
        $this->assertSame('old', $this->read('app/Old.php'));
        $this->assertSame(UpdateState::ROLLED_BACK, $this->state()->phase);
    }

    public function test_apply_refuses_to_write_through_a_symlinked_file(): void
    {
        $this->writeFile($this->temp.'/outside.txt', 'outside');
        $applier = $this->applier();
        $applier->stage($this->release());

        unlink($this->root.'/app/Old.php');

        if (! $this->makeSymlink($this->temp.'/outside.txt', $this->root.'/app/Old.php')) {
            file_put_contents($this->root.'/app/Old.php', 'old');
            $this->markTestSkipped('Symbolic links are not available on this machine.');
        }

        try {
            $applier->apply();
            $this->fail('Expected an UpdateException.');
        } catch (UpdateException) {
        } finally {
            $this->removeLink($this->root.'/app/Old.php');
        }

        $this->assertSame('outside', file_get_contents($this->temp.'/outside.txt'));
    }

    private function makeSymlink(string $target, string $link): bool
    {
        if (@symlink($target, $link)) {
            return true;
        }

        // Windows sans droit de créer des liens symboliques : une jonction de dossier (même effet pour le moteur).
        if (PHP_OS_FAMILY === 'Windows' && is_dir($target)) {
            $windows = fn (string $path): string => str_replace('/', DIRECTORY_SEPARATOR, $path);
            @exec('mklink /J "'.$windows($link).'" "'.$windows($target).'" 2>&1');

            return file_exists($link) && realpath($link) === realpath($target);
        }

        return false;
    }

    /** Retire un lien créé par makeSymlink (lien ou jonction), sans toucher à sa cible. */
    private function removeLink(string $link): void
    {
        if (! @unlink($link)) {
            @rmdir($link);
        }
    }

    // -- finish ------------------------------------------------------------------------------------------------

    public function test_finish_migrates_restores_the_maintenance_and_cleans_up(): void
    {
        $this->settings()->setMany(['maintenance.enabled' => true, 'maintenance.message' => 'Owner message']);
        $applier = $this->stagedAndApplied();

        $result = $applier->finish();

        $this->assertSame(['from' => '1.0.0', 'to' => '1.1.0', 'migrations_output' => 'Migrated.'], $result);
        $this->assertSame(1, $this->migrationRuns);
        $this->assertSame(1, $this->cacheClears);
        $this->assertTrue($this->settings()->maintenanceEnabled());
        $this->assertSame('Owner message', $this->settings()->maintenanceMessage());
        $this->assertSame(UpdateState::DONE, $this->state()->phase);
        $this->assertDirectoryDoesNotExist($this->root.'/storage/app/updates/work');
        $this->assertDirectoryDoesNotExist($this->root.'/storage/app/updates/previous');
        $this->assertSame('new old', $this->read('app/Old.php'));
        $this->assertSame(0, $this->restoreCalls);

        // Une nouvelle mise à jour est de nouveau possible, et finish ne se rejoue pas.
        try {
            $applier->finish();
            $this->fail('Expected an UpdateException.');
        } catch (UpdateException) {
        }

        $this->assertSame('1.2.0', $this->applier($this->zip('1.2.0'))->stage($this->release('1.2.0'))['to'] ?? null);
    }

    public function test_finish_turns_maintenance_off_when_it_was_off_before(): void
    {
        $this->settings()->setMany(['maintenance.enabled' => false, 'maintenance.message' => '']);
        $this->stagedAndApplied()->finish();

        $this->assertFalse($this->settings()->maintenanceEnabled());
    }

    public function test_finish_rolls_back_files_and_database_when_the_migrations_fail(): void
    {
        $this->settings()->setMany(['maintenance.enabled' => false, 'maintenance.message' => '']);
        $applier = $this->stagedAndApplied();
        $this->migrationFailure = 'SQLSTATE boom';

        try {
            $applier->finish();
            $this->fail('Expected an UpdateException.');
        } catch (UpdateException $e) {
            $this->assertStringContainsString('SQLSTATE boom', $e->getMessage());
            $this->assertStringContainsString('rolled back', $e->getMessage());
        }

        $this->assertApplicationUntouched();
        $this->assertSame(1, $this->restoreCalls);
        $this->assertFalse($this->settings()->maintenanceEnabled());
        $this->assertSame(0, $this->cacheClears);

        $state = $this->state();
        $this->assertSame(UpdateState::ROLLED_BACK, $state->phase);
        $this->assertTrue($state->migrationsStarted);
        $this->assertTrue($state->databaseRestored);
        $this->assertContains('SQLSTATE boom', $state->error);

        // Rejouer le retour arrière ne restaure pas la base une seconde fois.
        $applier->rollback();
        $this->assertSame(1, $this->restoreCalls);
    }

    public function test_finish_refuses_an_interrupted_replacement(): void
    {
        $applier = $this->applier();
        $applier->stage($this->release());
        $state = $this->state();
        $state->phase = UpdateState::SWAPPED;
        $state->swapComplete = false;
        $state->save();

        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('interrupted');

        $applier->finish();
    }

    public function test_finish_requires_a_swapped_update(): void
    {
        $applier = $this->applier();
        $applier->stage($this->release());

        $this->expectException(UpdateException::class);

        $applier->finish();
    }

    // -- rollback ----------------------------------------------------------------------------------------------

    public function test_rollback_restores_the_files_and_is_idempotent(): void
    {
        $this->writeFile($this->root.'/storage/app/updates/installed-files.json', (string) json_encode(['app/Gone.php']));
        $this->settings()->setMany(['maintenance.enabled' => false, 'maintenance.message' => '']);
        $applier = $this->stagedAndApplied();

        $this->assertSame('new old', $this->read('app/Old.php'));

        $applier->rollback();
        $this->assertApplicationUntouched();
        $this->assertSame(UpdateState::ROLLED_BACK, $this->state()->phase);
        $this->assertFalse($this->settings()->maintenanceEnabled());
        $this->assertSame(0, $this->restoreCalls, 'Migrations never started: the database stays as it is.');

        $installed = json_decode((string) $this->read('storage/app/updates/installed-files.json'), true);
        $this->assertSame(['app/Gone.php'], $installed, 'The list of the previous update is back.');

        $applier->rollback();
        $this->assertApplicationUntouched();
        $this->assertSame(UpdateState::ROLLED_BACK, $this->state()->phase);
    }

    public function test_rollback_without_an_unfinished_update_is_refused(): void
    {
        $this->expectException(UpdateException::class);

        $this->applier()->rollback();
    }

    public function test_rollback_resumes_after_a_brutal_stop_in_the_middle_of_apply(): void
    {
        $applier = $this->applier();
        $applier->stage($this->release());
        $work = $this->root.'/storage/app/updates/work/1.1.0';
        $previous = $this->root.'/storage/app/updates/previous/1.1.0';

        // Trace laissée par un arrêt en plein remplacement : app/Old.php copié puis remplacé, app/New.php créé,
        // artisan noté mais pas encore copié (donc intact), vendor non noté.
        $this->writeFile($previous.'/app/Old.php', 'old');
        $this->writeFile($this->root.'/app/Old.php', 'new old');
        $this->writeFile($this->root.'/app/New.php', 'new file');
        $this->assertFileExists($work.'/artisan');

        $state = $this->state();
        $state->phase = UpdateState::SWAPPED;
        $state->swapComplete = false;
        $state->replaced = ['app/Old.php', 'artisan'];
        $state->created = ['app/New.php', 'app/NeverWritten.php'];
        $state->save();

        $applier->rollback();

        $this->assertApplicationUntouched();
        $this->assertSame(UpdateState::ROLLED_BACK, $this->state()->phase);
    }

    public function test_rollback_keeps_going_after_an_error_and_records_it(): void
    {
        $applier = $this->applier();
        $applier->stage($this->release());
        $previous = $this->root.'/storage/app/updates/previous/1.1.0';

        $this->writeFile($previous.'/app/Old.php', 'old');
        $this->writeFile($previous.'/artisan', 'old artisan');
        // La cible d'app/Old.php est devenue un dossier : impossible à remettre.
        unlink($this->root.'/app/Old.php');
        mkdir($this->root.'/app/Old.php');
        $this->writeFile($this->root.'/artisan', 'new artisan');

        $state = $this->state();
        $state->phase = UpdateState::SWAPPED;
        $state->replaced = ['app/Old.php', 'artisan'];
        $state->save();

        $applier->rollback();

        $this->assertSame('old artisan', $this->read('artisan'), 'The other files are still put back.');

        $state = $this->state();
        $this->assertSame(UpdateState::FAILED, $state->phase);
        $this->assertNotSame([], $state->error);
        $this->assertStringContainsString('app/Old.php', $state->error[0]);
        $this->assertDirectoryExists($previous, 'The original copies are kept for a manual recovery.');
    }

    // -- État --------------------------------------------------------------------------------------------------

    public function test_a_corrupted_state_file_raises_a_clear_error(): void
    {
        $this->writeFile($this->root.'/storage/app/updates/state.json', '{not json');
        $applier = $this->applier();

        foreach ([fn () => $applier->state(), fn () => $applier->stage($this->release()), fn () => $applier->apply(), fn () => $applier->finish(), fn () => $applier->rollback()] as $call) {
            try {
                $call();
                $this->fail('Expected an UpdateException.');
            } catch (UpdateException $e) {
                $this->assertStringContainsString('state.json', $e->getMessage());
            }
        }

        $this->assertApplicationUntouched();
    }

    public function test_a_state_file_with_wrong_types_is_refused(): void
    {
        foreach (['{"phase":"nope"}', '{"phase":"idle","replaced":"x"}', '{"phase":"idle","replaced":[1]}', '{"phase":"idle","swap_complete":"yes"}', '{"phase":"idle","maintenance_before":{"enabled":1}}'] as $json) {
            $this->writeFile($this->root.'/storage/app/updates/state.json', $json);

            try {
                UpdateState::load($this->root.'/storage/app/updates/state.json');
                $this->fail('Expected an UpdateException for '.$json);
            } catch (UpdateException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_state_round_trips_and_is_written_atomically(): void
    {
        $file = $this->root.'/storage/app/updates/state.json';
        $this->assertSame(UpdateState::IDLE, UpdateState::load($file)->phase, 'No file means idle.');

        mkdir(dirname($file), 0775, true);
        $state = new UpdateState($file);
        $state->phase = UpdateState::SWAPPED;
        $state->from = '1.0.0';
        $state->to = '1.1.0';
        $state->backupId = 'b.zip';
        $state->maintenanceBefore = ['enabled' => true, 'message' => 'é'];
        $state->replaced = ['a'];
        $state->created = ['b'];
        $state->deleted = ['c'];
        $state->swapComplete = true;
        $state->error = ['oops'];
        $state->save();
        $state->save();

        $loaded = UpdateState::load($file);
        $this->assertEquals($state, $loaded);
        $this->assertSame(['state.json'], array_map('basename', glob(dirname($file).'/*')), 'No temporary file is left.');
    }
}
