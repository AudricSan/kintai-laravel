<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Cron;

use App\Console\Commands\BackupCommand;
use App\Console\Commands\LicenseCheckCommand;
use App\Console\Commands\LogPurgeCommand;
use App\Repositories\AppSettingsRepositoryInterface;
use App\Models\ActivityEntry;
use App\Services\AppSettingsService;
use App\Services\BackupService;
use App\Services\LicenseClientService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryAppSettings;

/** Commandes planifiées du Core (kintai:backup, log-purge, license-check) et planificateur de Laravel. */
final class ScheduledCommandsTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();
    }

    protected function tearDown(): void
    {
        app()->forgetInstance(AppSettingsService::class);
        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            array_map('unlink', glob($this->tmpDir . '/*') ?: []);
            rmdir($this->tmpDir);
        }
    }

    private function settings(array $values): void
    {
        app()->instance(AppSettingsService::class, new AppSettingsService(new InMemoryAppSettings($values)));
    }

    // ── Planificateur ──────────────────────────────────────────────────────────────────────────────────────────

    public function testEveryCoreCommandIsScheduled(): void
    {
        Artisan::all(); // charge routes/console.php
        $scheduled = implode("\n", array_map(static fn($event) => (string) $event->command, app(Schedule::class)->events()));

        foreach (['backup', 'log-purge', 'license-check', 'notification-purge', 'system-notifications', 'calendar-sync'] as $job) {
            $this->assertStringContainsString('kintai:' . $job, $scheduled, "tâche du Core absente du planificateur : {$job}");
            $this->assertArrayHasKey('kintai:' . $job, Artisan::all());
        }
    }

    // ── kintai:backup ──────────────────────────────────────────────────────────────────────────────────────────

    private function backup(array $input = []): array
    {
        $this->tmpDir = sys_get_temp_dir() . '/kintai_backupcmd_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0775, true);
        $service = new BackupService(null);
        (new \ReflectionProperty(BackupService::class, 'backupDir'))->setValue($service, $this->tmpDir);
        app()->instance(BackupService::class, $service);

        [$code, $output] = kintai_test_command(new BackupCommand(), $input);
        app()->forgetInstance(BackupService::class);

        return [$code, json_decode($output, true)];
    }

    public function testBackupDoesNothingUntilTheOwnerEnablesIt(): void
    {
        $this->settings(['backup_auto_enabled' => '0']);

        [$code, $data] = $this->backup();

        $this->assertSame(0, $code);
        $this->assertFalse($data['ok']);
    }

    public function testBackupCreatesAFileWhenEnabled(): void
    {
        $this->settings(['backup_auto_enabled' => '1']);

        [$code, $data] = $this->backup(['--note' => 'Avant mise à jour']);

        $this->assertSame(0, $code);
        $this->assertTrue($data['ok']);
        $this->assertStringContainsString('backup_', $data['filename']);
        $this->assertIsInt($data['size']);
    }

    // ── kintai:log-purge ───────────────────────────────────────────────────────────────────────────────────────

    private function logEntry(int $daysAgo): void
    {
        ActivityEntry::create(['level' => 'info', 'channel' => 'business', 'message' => 'x', 'created_at' => date('Y-m-d H:i:s', strtotime("-{$daysAgo} days"))]);
    }

    private function purgeLog(array $settings): array
    {
        $this->settings($settings);
        [, $output] = kintai_test_command(new LogPurgeCommand());

        return json_decode($output, true);
    }

    public function testLogPurgeUsesTheConfiguredRetention(): void
    {
        $this->logEntry(100);
        $this->logEntry(10);

        $data = $this->purgeLog(['log_retention_days' => '30']);

        $this->assertTrue($data['ok']);
        $this->assertSame(1, $data['deleted']);
        $this->assertSame(date('Y-m-d H:i:s', strtotime('-30 days')), $data['cutoff']);
    }

    public function testLogPurgeSkipsWhenRetentionIsUnlimited(): void
    {
        $this->logEntry(1000);

        $data = $this->purgeLog(['log_retention_days' => '0']);

        $this->assertFalse($data['ok']);
        $this->assertSame(1, ActivityEntry::where('message', 'x')->count());
    }

    // ── kintai:license-check ───────────────────────────────────────────────────────────────────────────────────

    private function license(array $store, ?callable $transport = null): array
    {
        $settings = $this->createStub(AppSettingsRepositoryInterface::class);
        $settings->method('get')->willReturnCallback(fn(string $k) => $store[$k] ?? null);
        app()->instance(LicenseClientService::class, new LicenseClientService(
            $settings,
            ['base_url' => 'https://license.test/api/v1', 'api_key' => 'kintai-key', 'grace_period_days' => 14],
            transport: $transport,
        ));
        [, $output] = kintai_test_command(new LicenseCheckCommand());
        app()->forgetInstance(LicenseClientService::class);

        return json_decode($output, true);
    }

    public function testLicenseCheckIsANoopWithoutAKey(): void
    {
        $data = $this->license([]);

        $this->assertTrue($data['ok']);
        $this->assertFalse($data['checked']);
    }

    public function testLicenseCheckRefreshesAnActiveLicense(): void
    {
        $data = $this->license(['license_key' => 'KEY-1'], fn(): string => json_encode(['valid' => true, 'status' => 'active']));

        $this->assertTrue($data['checked']);
        $this->assertTrue($data['valid']);
    }
}
