<?php

namespace Tests\Feature\Backups;

use App\Backups\BackupException;
use App\Backups\BackupInfo;
use App\Backups\BackupManager;
use App\Settings\AppSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesBackupDirectory;
use Tests\TestCase;

class RunBackupCommandTest extends TestCase
{
    use RefreshDatabase;
    use UsesBackupDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBackupDirectory();
    }

    private function settings(): AppSettings
    {
        return $this->app->make(AppSettings::class);
    }

    public function test_it_creates_a_scheduled_backup_and_records_the_success(): void
    {
        $this->settings()->set('backup.last_failure', 'old failure');

        $this->artisan('kintai:backup:run')->assertSuccessful();

        $backups = $this->backups()->all();
        $this->assertCount(1, $backups);
        $this->assertSame('scheduled', $backups[0]->trigger);
        $this->assertNotNull($this->settings()->backupLastSuccessAt());
        $this->assertTrue($this->settings()->backupLastSuccessAt()->isToday());
        $this->assertSame('', $this->settings()->backupLastFailure());
    }

    public function test_it_does_nothing_when_disabled_unless_forced(): void
    {
        $this->settings()->set('backup.auto_enabled', false);

        $this->artisan('kintai:backup:run')->assertSuccessful();
        $this->assertSame([], $this->backups()->all());
        $this->assertNull($this->settings()->backupLastSuccessAt());

        $this->artisan('kintai:backup:run --force')->assertSuccessful();
        $this->assertCount(1, $this->backups()->all());
    }

    public function test_it_applies_the_rotation(): void
    {
        $this->settings()->set('backup.max_keep', 2);

        foreach (range(1, 4) as $n) {
            $this->artisan('kintai:backup:run')->assertSuccessful();
        }

        $this->assertCount(2, $this->backups()->all());

        $this->settings()->set('backup.max_keep', 0);
        $this->artisan('kintai:backup:run')->assertSuccessful();
        $this->artisan('kintai:backup:run')->assertSuccessful();
        $this->assertCount(4, $this->backups()->all(), '0 keeps everything');
    }

    public function test_a_failure_is_recorded_reported_and_does_not_hide_the_last_success(): void
    {
        $this->settings()->set('backup.last_success_at', '2026-11-01T03:00:00Z');
        $reported = [];
        $this->app->make(ExceptionHandler::class)->reportable(function (\Throwable $e) use (&$reported) {
            $reported[] = $e::class;

            return false;
        });
        $this->app->bind(BackupManager::class, fn () => new class extends BackupManager
        {
            public function create(?string $note = null, string $trigger = self::TRIGGER_MANUAL): BackupInfo
            {
                throw new \RuntimeException('SQLSTATE[HY000] secret path /var/www/.env');
            }
        });

        $this->artisan('kintai:backup:run')->assertFailed();

        $failure = $this->settings()->backupLastFailure();
        $this->assertNotSame('', $failure);
        $this->assertStringContainsString('could not be created', $failure);
        $this->assertStringNotContainsString('SQLSTATE', $failure, 'technical details stay in the log');
        $this->assertStringNotContainsString('/var/www', $failure);
        $this->assertSame([\RuntimeException::class], $reported);
        $this->assertSame('2026-11-01', $this->settings()->backupLastSuccessAt()?->format('Y-m-d'));
    }

    public function test_a_backup_exception_message_is_kept_as_it_is_safe(): void
    {
        $this->app->bind(BackupManager::class, fn () => new class extends BackupManager
        {
            public function create(?string $note = null, string $trigger = self::TRIGGER_MANUAL): BackupInfo
            {
                throw new BackupException('The backups folder cannot be written.');
            }
        });

        $this->artisan('kintai:backup:run')->assertFailed();

        $this->assertStringContainsString('The backups folder cannot be written.', $this->settings()->backupLastFailure());
    }

    public function test_a_later_success_clears_the_failure(): void
    {
        $this->settings()->set('backup.last_failure', 'x');

        $this->artisan('kintai:backup:run')->assertSuccessful();

        $this->assertSame('', $this->settings()->backupLastFailure());
    }

    public function test_it_is_scheduled_daily_only_when_enabled(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'kintai:backup:run'));

        $this->assertNotNull($event);
        $this->assertSame('0 3 * * *', $event->expression);
        $this->assertTrue($event->filtersPass($this->app));

        $this->settings()->set('backup.auto_enabled', false);
        $this->assertFalse($event->filtersPass($this->app));
    }
}
