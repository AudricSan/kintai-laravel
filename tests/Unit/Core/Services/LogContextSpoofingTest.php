<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Services;

use kintai\Core\Container;
use kintai\Core\Repositories\LogRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Services\Log;
use PHPUnit\Framework\TestCase;

/**
 * Audit du 01/10/2026 (point 5) : le journal d'erreurs lisait l'utilisateur dans $_REQUEST (« ?auth_user[id]=1 »
 * l'attribuait à n'importe qui) et l'IP dans X-Forwarded-For / Client-IP, deux en-têtes envoyés par le client.
 */
final class LogContextSpoofingTest extends TestCase
{
    /** @var array<int, mixed> arguments du dernier appel à record() */
    private array $recorded = [];
    private Container $container;
    private array $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $repo = $this->createStub(LogRepositoryInterface::class);
        $repo->method('record')->willReturnCallback(function (...$args): void {
            $this->recorded = $args;
        });

        $this->container = new Container();
        $this->container->instance(LogRepositoryInterface::class, $repo);
        Log::setContainer($this->container);
    }

    protected function tearDown(): void
    {
        Log::reset();
        $_SERVER  = $this->server;
        $_REQUEST = [];
        $_GET     = [];
    }

    private function request(): Request
    {
        $_GET = [];
        $_POST = $_COOKIE = $_FILES = [];
        return new Request();
    }

    public function testUserComesFromTheAuthenticatedRequestNotFromTheQueryString(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/x?auth_user[id]=1', 'SCRIPT_NAME' => '/index.php', 'REMOTE_ADDR' => '203.0.113.7'];
        $_REQUEST = ['auth_user' => ['id' => '1', 'store_id' => '1']];
        $request = $this->request();
        $request->setAttribute('auth_user', ['id' => 42, 'store_id' => 3]);
        $this->container->instance(Request::class, $request);

        Log::write(LogRepositoryInterface::LEVEL_ERROR, LogRepositoryInterface::CHANNEL_BUSINESS, 'boom');

        $this->assertSame(42, $this->recorded[7], 'utilisateur');
        $this->assertSame(3, $this->recorded[8], 'magasin');
    }

    public function testForgedUserIsIgnoredForAnonymousVisitors(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/x', 'SCRIPT_NAME' => '/index.php', 'REMOTE_ADDR' => '203.0.113.7'];
        $_REQUEST = ['auth_user' => ['id' => '1']];
        $this->container->instance(Request::class, $this->request());

        Log::write(LogRepositoryInterface::LEVEL_ERROR, LogRepositoryInterface::CHANNEL_BUSINESS, 'boom');

        $this->assertNull($this->recorded[7]);
    }

    public function testIpIsTheRealConnectionAddress(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/x', 'SCRIPT_NAME' => '/index.php',
            'REMOTE_ADDR' => '203.0.113.7', 'HTTP_X_FORWARDED_FOR' => '10.6.6.6', 'HTTP_CLIENT_IP' => '10.7.7.7',
        ];
        $this->container->instance(Request::class, $this->request());

        Log::write(LogRepositoryInterface::LEVEL_ERROR, LogRepositoryInterface::CHANNEL_BUSINESS, 'boom');

        $this->assertSame('203.0.113.7', $this->recorded[9]);
    }
}
