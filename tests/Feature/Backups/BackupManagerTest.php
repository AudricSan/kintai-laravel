<?php

namespace Tests\Feature\Backups;

use App\Backups\BackupException;
use App\Backups\BackupInfo;
use App\Backups\BackupManager;
use App\Backups\DatabaseDumper;
use App\Models\Store;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PDO;
use Tests\Concerns\UsesBackupDirectory;
use Tests\TestCase;
use ZipArchive;

class BackupManagerTest extends TestCase
{
    use RefreshDatabase;
    use UsesBackupDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBackupDirectory();
    }

    public function test_it_creates_an_archive_with_the_database_the_uploaded_files_and_a_manifest(): void
    {
        Store::factory()->create(['name' => 'Tokyo Shibuya', 'code' => 'TKY-1']);
        Storage::disk('local')->put('avatars/user_1_abcdefabcdefabcd.png', 'PNGDATA');
        Storage::disk('local')->put('other/secret.txt', 'not an avatar');

        $backup = $this->backups()->create('Before the audit');

        $this->assertMatchesRegularExpression('/^backup_\d{8}_\d{6}_[0-9a-f]{8}\.zip$/', $backup->id);
        $this->assertSame($this->backupDir.DIRECTORY_SEPARATOR.$backup->id, $backup->path);
        $this->assertFileExists($backup->path);
        $this->assertSame(['backup.json', 'database.sqlite', 'files/avatars/user_1_abcdefabcdefabcd.png'], $this->archiveEntries($backup->id));

        $zip = new ZipArchive;
        $zip->open($backup->path);
        $manifest = json_decode((string) $zip->getFromName('backup.json'), true);
        $this->assertSame('PNGDATA', $zip->getFromName('files/avatars/user_1_abcdefabcdefabcd.png'));

        $this->assertSame(1, $manifest['format']);
        $this->assertSame('Before the audit', $manifest['note']);
        $this->assertSame('sqlite', $manifest['driver']);
        $this->assertSame('database.sqlite', $manifest['database_file']);
        $this->assertSame(config('kintai.version'), $manifest['app_version']);
        $this->assertSame('2026_11_07_000001_create_app_settings_table', $manifest['last_migration']);
        $this->assertSame(1, $manifest['files']);
        $this->assertGreaterThan(0, $manifest['size']);
        $this->assertSame('manual', $manifest['trigger']);

        // La base de l'archive est un fichier SQLite autonome qui contient les données.
        $file = $this->backupDir.DIRECTORY_SEPARATOR.'extracted.sqlite';
        file_put_contents($file, $zip->getFromName('database.sqlite'));
        $zip->close();
        $pdo = new PDO('sqlite:'.$file);
        $this->assertSame('Tokyo Shibuya', $pdo->query("SELECT name FROM stores WHERE code = 'TKY-1'")->fetchColumn());
        $this->assertSame('ok', $pdo->query('PRAGMA integrity_check')->fetchColumn());
        unset($pdo);
    }

    public function test_the_listing_reads_the_manifest_and_orders_newest_first(): void
    {
        $first = $this->backups()->create('first');
        $second = $this->backups()->create('second', BackupManager::TRIGGER_SCHEDULED);

        $listed = $this->backups()->all();

        $this->assertSame([$second->id, $first->id], array_map(fn ($b) => $b->id, $listed));
        $this->assertSame('second', $listed[0]->note);
        $this->assertSame('scheduled', $listed[0]->trigger);
        $this->assertSame('sqlite', $listed[0]->driver);
        $this->assertSame(config('kintai.version'), $listed[0]->version);
        $this->assertTrue($listed[0]->valid);
        $this->assertSame(filesize($second->path), $listed[0]->size);
    }

    public function test_foreign_files_and_unreadable_archives_are_handled(): void
    {
        $directory = $this->backups()->directory();
        file_put_contents($directory.'/notes.txt', 'x');
        file_put_contents($directory.'/backup_bad.zip', 'x');
        file_put_contents($directory.'/backup_20260101_000000_deadbeef.zip', 'not a zip');

        $listed = $this->backups()->all();

        $this->assertCount(1, $listed);
        $this->assertFalse($listed[0]->valid);
        $this->assertSame('backup_20260101_000000_deadbeef.zip', $listed[0]->id);
    }

    public function test_find_only_returns_what_the_folder_contains(): void
    {
        $backup = $this->backups()->create();

        $this->assertSame($backup->id, $this->backups()->find($backup->id)?->id);
        $this->assertNull($this->backups()->find('backup_20260101_000000_00000000.zip'));
        $this->assertNull($this->backups()->find('../'.$backup->id));
        $this->assertNull($this->backups()->find($this->backupDir.'/'.$backup->id));
        $this->assertNull($this->backups()->find('..'));
        $this->assertNull($this->backups()->find(''));
    }

    public function test_the_backups_folder_is_closed_to_the_web(): void
    {
        $this->backups()->create();

        $this->assertFileExists($this->backupDir.'/.htaccess');
        $this->assertStringContainsString('Deny from all', (string) file_get_contents($this->backupDir.'/.htaccess'));
        $this->assertStringNotContainsString(public_path(), $this->backupDir);
    }

    public function test_a_failed_dump_leaves_no_partial_archive(): void
    {
        $failing = new class implements DatabaseDumper
        {
            public function driver(): string
            {
                return 'sqlite';
            }

            public function fileName(): string
            {
                return 'database.sqlite';
            }

            public function dump(string $path): void
            {
                file_put_contents($path, 'partial');

                throw new BackupException('The database could not be exported.');
            }

            public function restore(string $path): void {}
        };

        try {
            (new BackupManager($failing))->create('will fail');
            $this->fail('The backup should have failed.');
        } catch (BackupException) {
            // attendu
        }

        $this->assertSame([], $this->backups()->all());
        $this->assertSame([], array_values(array_filter(scandir($this->backupDir), fn ($f) => str_starts_with($f, '.work_') || str_starts_with($f, '.tmp_'))));
    }

    public function test_it_deletes_one_backup_or_all_of_them(): void
    {
        $one = $this->backups()->create();
        $two = $this->backups()->create();
        $three = $this->backups()->create();

        $this->backups()->delete($one);
        $this->assertFileDoesNotExist($one->path);
        $this->assertCount(2, $this->backups()->all());

        $this->assertSame(2, $this->backups()->deleteAll());
        $this->assertSame([], $this->backups()->all());
        $this->assertFileDoesNotExist($two->path);
        $this->assertFileDoesNotExist($three->path);
    }

    public function test_delete_refuses_a_path_outside_the_backups_folder(): void
    {
        $backup = $this->backups()->create();
        $outside = sys_get_temp_dir().DIRECTORY_SEPARATOR.$backup->id;
        copy($backup->path, $outside);

        $forged = new BackupInfo($backup->id, $outside, 1, $backup->createdAt, '', '', '', 'manual', true);

        try {
            $this->backups()->delete($forged);
            $this->fail('A path outside the folder must be refused.');
        } catch (BackupException) {
            $this->assertFileExists($outside);
        } finally {
            @unlink($outside);
        }
    }

    public function test_rotation_keeps_the_newest_backups(): void
    {
        $ids = [];
        foreach (range(1, 5) as $n) {
            $ids[] = $this->backups()->create("n{$n}")->id;
        }

        $this->assertSame(0, $this->backups()->prune(0), '0 means unlimited');
        $this->assertCount(5, $this->backups()->all());

        $this->assertSame(3, $this->backups()->prune(2));

        $this->assertSame([$ids[4], $ids[3]], array_map(fn ($b) => $b->id, $this->backups()->all()));
        $this->assertSame(0, $this->backups()->prune(10));
    }

    public function test_a_symbolic_link_is_not_a_backup(): void
    {
        $backup = $this->backups()->create();
        $target = sys_get_temp_dir().DIRECTORY_SEPARATOR.'kintai-link-target-'.bin2hex(random_bytes(3)).'.zip';
        copy($backup->path, $target);
        $link = $this->backupDir.'/backup_20260202_020202_abcdef12.zip';

        if (! @symlink($target, $link)) {
            @unlink($target);
            $this->markTestSkipped('Symbolic links are not available here.');
        }

        try {
            $this->assertNull($this->backups()->find('backup_20260202_020202_abcdef12.zip'));
            $this->assertCount(1, $this->backups()->all());
        } finally {
            @unlink($link);
            @unlink($target);
        }
    }

    public function test_an_unsupported_driver_is_refused_cleanly(): void
    {
        $connection = \Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn('pgsql');
        DB::shouldReceive('connection')->andReturn($connection);

        try {
            $this->backups()->create();
            $this->fail('An unsupported driver must be refused.');
        } catch (BackupException $e) {
            $this->assertStringContainsString('not available for this database type', $e->getMessage());
        }

        $this->assertSame([], glob($this->backupDir.'/backup_*.zip') ?: []);
    }
}
