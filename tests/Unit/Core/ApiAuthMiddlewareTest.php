<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use kintai\Core\Container;
use kintai\Core\Middleware\ApiAuthMiddleware;
use kintai\Core\Repositories\ApiTokenRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class ApiAuthMiddlewareTest extends TestCase
{
    private ApiTokenRepositoryInterface&Stub $tokens;
    private UserRepositoryInterface&Stub     $users;
    private ApiAuthMiddleware $middleware;

    protected function setUp(): void
    {
        $this->tokens = $this->createStub(ApiTokenRepositoryInterface::class);
        $this->users  = $this->createStub(UserRepositoryInterface::class);

        $container = Container::getInstance();
        $container->instance(ApiTokenRepositoryInterface::class, $this->tokens);
        $container->instance(UserRepositoryInterface::class, $this->users);

        $this->middleware = new ApiAuthMiddleware($container);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeRequest(string $authorization = ''): Request
    {
        $server = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI'    => '/api/v1/users',
            'SCRIPT_NAME'    => '/index.php',
        ];
        if ($authorization !== '') {
            $server['HTTP_AUTHORIZATION'] = $authorization;
        }

        $_SERVER = $server;
        $_GET    = [];
        $_POST   = [];
        $_COOKIE = [];
        $_FILES  = [];

        return new Request();
    }

    protected function tearDown(): void
    {
        $_SERVER = [];
        $_GET    = [];
        $_POST   = [];
    }

    private function noop(): \Closure
    {
        return fn(Request $req) => Response::json(['ok' => true]);
    }

    // -------------------------------------------------------------------------
    // Token extraction
    // -------------------------------------------------------------------------

    public function testMissingAuthorizationHeaderReturns401(): void
    {
        $req      = $this->makeRequest();
        $response = $this->middleware->handle($req, $this->noop());

        $this->assertSame(401, $response->status());
        $this->assertStringContainsString('MISSING_TOKEN', $response->body());
    }

    public function testNonBearerSchemeReturns401(): void
    {
        $req      = $this->makeRequest('Basic abc123');
        $response = $this->middleware->handle($req, $this->noop());

        $this->assertSame(401, $response->status());
        $this->assertStringContainsString('MISSING_TOKEN', $response->body());
    }

    public function testEmptyTokenAfterBearerReturns401(): void
    {
        $req      = $this->makeRequest('Bearer ');
        $response = $this->middleware->handle($req, $this->noop());

        $this->assertSame(401, $response->status());
        $this->assertStringContainsString('MISSING_TOKEN', $response->body());
    }

    // -------------------------------------------------------------------------
    // Token validation
    // -------------------------------------------------------------------------

    public function testUnknownTokenReturns401(): void
    {
        $this->tokens->method('findByToken')->willReturn(null);

        $req      = $this->makeRequest('Bearer unknowntoken');
        $response = $this->middleware->handle($req, $this->noop());

        $this->assertSame(401, $response->status());
        $this->assertStringContainsString('INVALID_TOKEN', $response->body());
    }

    public function testExpiredTokenReturns401(): void
    {
        $this->tokens->method('findByToken')->willReturn([
            'id'         => 1,
            'user_id'    => 1,
            'token'      => 'abc',
            'expires_at' => '2000-01-01 00:00:00',
        ]);

        $req      = $this->makeRequest('Bearer abc');
        $response = $this->middleware->handle($req, $this->noop());

        $this->assertSame(401, $response->status());
        $this->assertStringContainsString('EXPIRED_TOKEN', $response->body());
    }

    public function testTokenWithNoExpiryIsNotExpired(): void
    {
        $this->tokens->method('findByToken')->willReturn([
            'id'         => 1,
            'user_id'    => 1,
            'token'      => 'abc',
            'expires_at' => null,
        ]);
        $this->tokens->method('save')->willReturn([]);
        $this->users->method('findById')->willReturn([
            'id'        => 1,
            'is_active' => true,
            'deleted_at' => null,
        ]);

        $req      = $this->makeRequest('Bearer abc');
        $response = $this->middleware->handle($req, $this->noop());

        $this->assertSame(200, $response->status());
    }

    public function testTokenWithEmptyExpiryIsNotExpired(): void
    {
        $this->tokens->method('findByToken')->willReturn([
            'id'         => 1,
            'user_id'    => 1,
            'token'      => 'abc',
            'expires_at' => '',
        ]);
        $this->tokens->method('save')->willReturn([]);
        $this->users->method('findById')->willReturn([
            'id'         => 1,
            'is_active'  => true,
            'deleted_at' => null,
        ]);

        $req      = $this->makeRequest('Bearer abc');
        $response = $this->middleware->handle($req, $this->noop());

        $this->assertSame(200, $response->status());
    }

    // -------------------------------------------------------------------------
    // User validation
    // -------------------------------------------------------------------------

    public function testNullUserReturns401(): void
    {
        $this->tokens->method('findByToken')->willReturn([
            'id' => 1, 'user_id' => 99, 'token' => 'abc', 'expires_at' => null,
        ]);
        $this->users->method('findById')->willReturn(null);

        $req      = $this->makeRequest('Bearer abc');
        $response = $this->middleware->handle($req, $this->noop());

        $this->assertSame(401, $response->status());
        $this->assertStringContainsString('INACTIVE_USER', $response->body());
    }

    public function testInactiveUserReturns401(): void
    {
        $this->tokens->method('findByToken')->willReturn([
            'id' => 1, 'user_id' => 2, 'token' => 'abc', 'expires_at' => null,
        ]);
        $this->users->method('findById')->willReturn([
            'id' => 2, 'is_active' => false, 'deleted_at' => null,
        ]);

        $req      = $this->makeRequest('Bearer abc');
        $response = $this->middleware->handle($req, $this->noop());

        $this->assertSame(401, $response->status());
        $this->assertStringContainsString('INACTIVE_USER', $response->body());
    }

    public function testSoftDeletedUserReturns401(): void
    {
        $this->tokens->method('findByToken')->willReturn([
            'id' => 1, 'user_id' => 2, 'token' => 'abc', 'expires_at' => null,
        ]);
        $this->users->method('findById')->willReturn([
            'id' => 2, 'is_active' => true, 'deleted_at' => '2025-01-01 00:00:00',
        ]);

        $req      = $this->makeRequest('Bearer abc');
        $response = $this->middleware->handle($req, $this->noop());

        $this->assertSame(401, $response->status());
        $this->assertStringContainsString('INACTIVE_USER', $response->body());
    }

    // -------------------------------------------------------------------------
    // Success path
    // -------------------------------------------------------------------------

    public function testValidTokenSetsAuthAttributes(): void
    {
        $tokenRecord = ['id' => 5, 'user_id' => 3, 'token' => 'validtoken', 'expires_at' => null];
        $userRecord  = ['id' => 3, 'is_active' => true, 'deleted_at' => null];

        $this->tokens->method('findByToken')->willReturn($tokenRecord);
        $this->tokens->method('save')->willReturn([]);
        $this->users->method('findById')->willReturn($userRecord);

        $capturedReq = null;
        $next        = function (Request $req) use (&$capturedReq): Response {
            $capturedReq = $req;
            return Response::json(['ok' => true]);
        };

        $req      = $this->makeRequest('Bearer validtoken');
        $response = $this->middleware->handle($req, $next);

        $this->assertSame(200, $response->status());
        $this->assertSame($userRecord, $capturedReq->getAttribute('auth_user'));
        $this->assertSame($tokenRecord, $capturedReq->getAttribute('api_token'));
    }

    public function testValidTokenUpdatesLastUsedAt(): void
    {
        $this->tokens = $this->createMock(ApiTokenRepositoryInterface::class);
        Container::getInstance()->instance(ApiTokenRepositoryInterface::class, $this->tokens);
        $tokenRecord = ['id' => 5, 'user_id' => 3, 'token' => 'validtoken', 'expires_at' => null];
        $userRecord  = ['id' => 3, 'is_active' => true, 'deleted_at' => null];

        $this->tokens->method('findByToken')->willReturn($tokenRecord);
        $this->users->method('findById')->willReturn($userRecord);

        $this->tokens->expects($this->once())
            ->method('save')
            ->with($this->callback(fn($data) => isset($data['last_used_at'])));

        $req = $this->makeRequest('Bearer validtoken');
        $this->middleware->handle($req, $this->noop());
    }

    public function testLastUsedAtUpdateFailureDoesNotBlockRequest(): void
    {
        $tokenRecord = ['id' => 5, 'user_id' => 3, 'token' => 'validtoken', 'expires_at' => null];
        $userRecord  = ['id' => 3, 'is_active' => true, 'deleted_at' => null];

        $this->tokens->method('findByToken')->willReturn($tokenRecord);
        $this->tokens->method('save')->willThrowException(new \RuntimeException('DB error'));
        $this->users->method('findById')->willReturn($userRecord);

        $req      = $this->makeRequest('Bearer validtoken');
        $response = $this->middleware->handle($req, $this->noop());

        $this->assertSame(200, $response->status());
    }
}
