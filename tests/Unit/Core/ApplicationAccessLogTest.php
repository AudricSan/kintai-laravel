<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use kintai\Core\Application;
use kintai\Core\Container;
use kintai\Core\Repositories\AppSettingsRepositoryInterface;
use kintai\Core\Repositories\LogRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\AppSettingsService;
use kintai\Core\Services\Log;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Teste Application::logAccess() (méthode privée, invoquée par handleRequest() après
 * chaque requête) en isolation, sans passer par le vrai bootstrap (routes/kintai.php,
 * providers...) qui rendrait un test de Application::class trop coûteux à construire.
 */
final class ApplicationAccessLogTest extends TestCase
{
    private LogRepositoryInterface&Stub $logs;
    private AppSettingsRepositoryInterface&Stub $settingsRepo;
    private array $storedSettings = [];

    protected function setUp(): void
    {
        $this->logs = $this->createStub(LogRepositoryInterface::class);
        $this->settingsRepo = $this->createStub(AppSettingsRepositoryInterface::class);
        $this->settingsRepo->method('all')->willReturnCallback(fn() => $this->storedSettings);
    }

    protected function tearDown(): void
    {
        Log::reset();
        $_SERVER = [];
    }

    private function makeApplication(): Application
    {
        $container = new Container();
        $container->instance(LogRepositoryInterface::class, $this->logs);
        $container->instance(AppSettingsRepositoryInterface::class, $this->settingsRepo);
        Log::setContainer($container);

        $app = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
        $ref = new \ReflectionProperty(Application::class, 'container');
        $ref->setAccessible(true);
        $ref->setValue($app, $container);

        return $app;
    }

    private function callLogAccess(Application $app, Request $request, Response $response, float $startedAt): void
    {
        $method = new \ReflectionMethod(Application::class, 'logAccess');
        $method->setAccessible(true);
        $method->invoke($app, $request, $response, $startedAt);
    }

    private function makeRequest(string $uri, string $method = 'GET'): Request
    {
        $_SERVER = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'SCRIPT_NAME' => '/index.php'];
        return new Request();
    }

    public function testLogsSuccessfulRequestAsInfo(): void
    {
        $this->logs = $this->createMock(LogRepositoryInterface::class);
        $app = $this->makeApplication();

        $this->logs->expects($this->once())->method('record')->with(
            LogRepositoryInterface::LEVEL_INFO,
            LogRepositoryInterface::CHANNEL_ACCESS,
            'GET /admin/shifts',
        );

        $this->callLogAccess($app, $this->makeRequest('/admin/shifts'), Response::html('ok', 200), microtime(true));
    }

    public function testLogsServerErrorAsError(): void
    {
        $this->logs = $this->createMock(LogRepositoryInterface::class);
        $app = $this->makeApplication();

        $this->logs->expects($this->once())->method('record')->with(
            LogRepositoryInterface::LEVEL_ERROR,
            LogRepositoryInterface::CHANNEL_ACCESS,
            $this->anything(),
        );

        $this->callLogAccess($app, $this->makeRequest('/admin/shifts'), Response::html('boom', 500), microtime(true));
    }

    public function testLogsClientErrorAsWarning(): void
    {
        $this->logs = $this->createMock(LogRepositoryInterface::class);
        $app = $this->makeApplication();

        $this->logs->expects($this->once())->method('record')->with(
            LogRepositoryInterface::LEVEL_WARNING,
            LogRepositoryInterface::CHANNEL_ACCESS,
            $this->anything(),
        );

        $this->callLogAccess($app, $this->makeRequest('/admin/shifts'), Response::html('nope', 404), microtime(true));
    }

    public function testSkipsNotificationPollRoute(): void
    {
        $this->logs = $this->createMock(LogRepositoryInterface::class);
        $app = $this->makeApplication();

        $this->logs->expects($this->never())->method('record');

        $this->callLogAccess($app, $this->makeRequest('/notifications/poll'), Response::json([]), microtime(true));
    }

    public function testSkipsApiPingRoute(): void
    {
        $this->logs = $this->createMock(LogRepositoryInterface::class);
        $app = $this->makeApplication();

        $this->logs->expects($this->never())->method('record');

        $this->callLogAccess($app, $this->makeRequest('/api/v1/ping'), Response::json([]), microtime(true));
    }

    public function testSkipsStreamingRoutes(): void
    {
        $this->logs = $this->createMock(LogRepositoryInterface::class);
        $app = $this->makeApplication();

        $this->logs->expects($this->never())->method('record');

        $this->callLogAccess($app, $this->makeRequest('/messages/42/stream'), Response::html(''), microtime(true));
    }

    public function testSkipsWhenAccessLogDisabledViaSettings(): void
    {
        $this->logs = $this->createMock(LogRepositoryInterface::class);
        $this->storedSettings = ['access_log_enabled' => '0'];
        $app = $this->makeApplication();

        $this->logs->expects($this->never())->method('record');

        $this->callLogAccess($app, $this->makeRequest('/admin/shifts'), Response::html('ok', 200), microtime(true));
    }

    public function testNeverThrowsWhenLoggingFails(): void
    {
        $this->logs->method('record')->willThrowException(new \RuntimeException('db down'));
        $app = $this->makeApplication();

        $this->callLogAccess($app, $this->makeRequest('/admin/shifts'), Response::html('ok', 200), microtime(true));

        $this->addToAssertionCount(1);
    }
}
