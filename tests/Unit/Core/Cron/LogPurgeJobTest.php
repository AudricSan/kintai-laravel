<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Cron;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use kintai\Core\Cron\LogPurgeJob;
use kintai\Core\Repositories\AppSettingsRepositoryInterface;
use kintai\Core\Repositories\LogRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Services\AppSettingsService;
use kintai\Core\Services\AuditLogger;

final class LogPurgeJobTest extends TestCase
{
    private LogRepositoryInterface&Stub $logs;
    private AppSettingsRepositoryInterface&Stub $settingsRepo;

    protected function setUp(): void
    {
        $this->logs = $this->createStub(LogRepositoryInterface::class);
        $this->settingsRepo = $this->createStub(AppSettingsRepositoryInterface::class);
    }

    private function makeJob(array $settings = []): LogPurgeJob
    {
        $this->settingsRepo->method('all')->willReturn($settings);
        return new LogPurgeJob($this->logs, new AppSettingsService($this->settingsRepo), new AuditLogger());
    }

    // -------------------------------------------------------------------------
    // getName()
    // -------------------------------------------------------------------------

    public function testGetNameReturnsLogPurge(): void
    {
        $this->assertSame('log-purge', $this->makeJob()->getName());
    }

    // -------------------------------------------------------------------------
    // run()
    // -------------------------------------------------------------------------

    public function testRunPurgesEntriesOlderThanRetentionByDefault(): void
    {
        $this->logs = $this->createMock(LogRepositoryInterface::class);
        $job = $this->makeJob();

        $this->logs->expects($this->once())
            ->method('purgeOlderThan')
            ->with($this->callback(fn(string $cutoff) => $cutoff === date('Y-m-d H:i:s', strtotime('-180 days'))))
            ->willReturn(42);

        $response = $job->run($this->makeRequest());
        $data     = json_decode($response->body(), true);

        $this->assertTrue($data['ok']);
        $this->assertSame(42, $data['deleted']);
    }

    public function testRunUsesConfiguredRetentionDays(): void
    {
        $this->logs = $this->createMock(LogRepositoryInterface::class);
        $job = $this->makeJob(['log_retention_days' => '30']);

        $this->logs->expects($this->once())
            ->method('purgeOlderThan')
            ->with($this->callback(fn(string $cutoff) => $cutoff === date('Y-m-d H:i:s', strtotime('-30 days'))))
            ->willReturn(5);

        $response = $job->run($this->makeRequest());
        $data     = json_decode($response->body(), true);

        $this->assertTrue($data['ok']);
        $this->assertSame(5, $data['deleted']);
    }

    public function testRunSkipsPurgeWhenRetentionIsUnlimited(): void
    {
        $this->logs = $this->createMock(LogRepositoryInterface::class);
        $job = $this->makeJob(['log_retention_days' => '0']);

        $this->logs->expects($this->never())->method('purgeOlderThan');

        $response = $job->run($this->makeRequest());
        $data     = json_decode($response->body(), true);

        $this->assertFalse($data['ok']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeRequest(array $query = []): Request
    {
        $queryStr = $query ? '?' . http_build_query($query) : '';
        $_SERVER  = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => "/cron/run/log-purge$queryStr", 'SCRIPT_NAME' => '/index.php'];
        $_GET     = $query;
        $_POST    = $_COOKIE = $_FILES = [];
        return new Request();
    }

    protected function tearDown(): void
    {
        $_SERVER = [];
        $_GET    = [];
        $_POST   = [];
    }
}
