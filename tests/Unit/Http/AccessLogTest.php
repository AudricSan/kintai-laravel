<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Container\Container;
use App\Repositories\AppSettingsRepositoryInterface;
use App\Repositories\LogRepositoryInterface;
use App\Services\Log;
use App\Http\Middleware\AccessLog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Journal d'accès automatique (AccessLog::terminate(), appelé après l'envoi de chaque réponse) : niveau selon le statut,
 * chemins exclus, réglage de l'instance, et jamais d'exception.
 */
final class AccessLogTest extends TestCase
{
    private array $storedSettings = [];

    protected function tearDown(): void
    {
        Log::reset();
        app()->forgetInstance(AppSettingsRepositoryInterface::class);
        app()->forgetInstance(\App\Services\AppSettingsService::class);
    }

    private function terminate(LogRepositoryInterface $logs, string $uri, int $status): void
    {
        $settings = $this->createStub(AppSettingsRepositoryInterface::class);
        $settings->method('all')->willReturnCallback(fn() => $this->storedSettings);
        app()->instance(AppSettingsRepositoryInterface::class, $settings);
        app()->forgetInstance(\App\Services\AppSettingsService::class);

        $container = new \Tests\Support\TestContainer();
        $container->instance(LogRepositoryInterface::class, $logs);
        Log::setContainer($container);

        $request = Request::create($uri, 'GET');
        (new AccessLog())->handle($request, static fn() => new Response());
        (new AccessLog())->terminate($request, new Response('', $status));
    }

    public function testLogsSuccessfulRequestAsInfo(): void
    {
        $logs = $this->createMock(LogRepositoryInterface::class);
        $logs->expects($this->once())->method('record')->with(
            LogRepositoryInterface::LEVEL_INFO,
            LogRepositoryInterface::CHANNEL_ACCESS,
            'GET /admin/shifts',
        );

        $this->terminate($logs, '/admin/shifts', 200);
    }

    public function testLogsServerErrorAsError(): void
    {
        $logs = $this->createMock(LogRepositoryInterface::class);
        $logs->expects($this->once())->method('record')->with(
            LogRepositoryInterface::LEVEL_ERROR,
            LogRepositoryInterface::CHANNEL_ACCESS,
            $this->anything(),
        );

        $this->terminate($logs, '/admin/shifts', 500);
    }

    public function testLogsClientErrorAsWarning(): void
    {
        $logs = $this->createMock(LogRepositoryInterface::class);
        $logs->expects($this->once())->method('record')->with(
            LogRepositoryInterface::LEVEL_WARNING,
            LogRepositoryInterface::CHANNEL_ACCESS,
            $this->anything(),
        );

        $this->terminate($logs, '/admin/shifts', 404);
    }

    /** @return array<string, array{string}> */
    public static function excludedPaths(): array
    {
        return [
            'polling des notifications' => ['/notifications/poll'],
            'health-check de l\'API'    => ['/api/v1/ping'],
            'flux en continu'           => ['/messages/42/stream'],
        ];
    }

    #[DataProvider('excludedPaths')]
    public function testSkipsHighFrequencyPaths(string $uri): void
    {
        $logs = $this->createMock(LogRepositoryInterface::class);
        $logs->expects($this->never())->method('record');

        $this->terminate($logs, $uri, 200);
    }

    public function testSkipsWhenAccessLogDisabledViaSettings(): void
    {
        $this->storedSettings = ['access_log_enabled' => '0'];
        $logs = $this->createMock(LogRepositoryInterface::class);
        $logs->expects($this->never())->method('record');

        $this->terminate($logs, '/admin/shifts', 200);
    }

    public function testNeverThrowsWhenLoggingFails(): void
    {
        $logs = $this->createStub(LogRepositoryInterface::class);
        $logs->method('record')->willThrowException(new \RuntimeException('db down'));

        $this->terminate($logs, '/admin/shifts', 200);

        $this->addToAssertionCount(1);
    }
}
