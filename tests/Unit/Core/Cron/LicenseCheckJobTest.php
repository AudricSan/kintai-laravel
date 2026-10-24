<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Cron;

use kintai\Core\Cron\LicenseCheckJob;
use kintai\Core\Repositories\AppSettingsRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Services\LicenseClientService;
use PHPUnit\Framework\TestCase;

final class LicenseCheckJobTest extends TestCase
{
    private array $store = [];

    protected function setUp(): void
    {
        $this->store = [];
    }

    private function makeJob(array $config = [], ?callable $transport = null): LicenseCheckJob
    {
        $appSettings = $this->createStub(AppSettingsRepositoryInterface::class);
        $appSettings->method('get')->willReturnCallback(fn(string $k) => $this->store[$k] ?? null);
        $appSettings->method('set')->willReturnCallback(function (string $k, string $v): void {
            $this->store[$k] = $v;
        });

        $config = array_merge(['base_url' => 'https://license.test/api/v1', 'api_key' => 'kintai-key', 'grace_period_days' => 14], $config);

        return new LicenseCheckJob(new LicenseClientService($appSettings, $config, transport: $transport));
    }

    public function testGetNameReturnsLicenseCheck(): void
    {
        $this->assertSame('license-check', $this->makeJob()->getName());
    }

    public function testRunIsNoopWhenNoLicenseKeyRegistered(): void
    {
        $response = $this->makeJob()->run(new Request());
        $data = json_decode($response->body(), true);

        $this->assertTrue($data['ok']);
        $this->assertFalse($data['checked']);
    }

    public function testRunRefreshesActiveLicense(): void
    {
        $this->store['license_key'] = 'KEY-1';
        $job = $this->makeJob([], fn(): string => json_encode(['valid' => true, 'status' => 'active']));

        $response = $job->run(new Request());
        $data = json_decode($response->body(), true);

        $this->assertTrue($data['ok']);
        $this->assertTrue($data['checked']);
        $this->assertTrue($data['valid']);
    }
}
