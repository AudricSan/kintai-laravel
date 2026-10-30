<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use Illuminate\Container\Container;
use App\Http\Middleware\ApiAuthMiddleware;
use App\Models\ApiToken;
use App\Repositories\UserRepositoryInterface;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class ApiAuthMiddlewareTest extends TestCase
{
    private UserRepositoryInterface&Stub     $users;
    private ApiAuthMiddleware $middleware;

    protected function setUp(): void
    {
        kintai_test_database();
        kintai_test_migrate();
        foreach ([1, 2, 3, 99] as $userId) {
            kintai_test_user($userId);
        }
        $this->users  = $this->createStub(UserRepositoryInterface::class);

        $container = Container::getInstance();
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

        return Request::capture();
    }

    protected function tearDown(): void
    {
        $_SERVER = [];
        $_GET    = [];
        $_POST   = [];
    }

    private function noop(): \Closure
    {
        return fn(Request $req) => response()->json(['ok' => true]);
    }

    // -------------------------------------------------------------------------
    // Token extraction
    // -------------------------------------------------------------------------

    public function testMissingAuthorizationHeaderReturns401(): void
    {
        $req      = $this->makeRequest();
        $response = $this->middleware->handle($req, $this->noop());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('MISSING_TOKEN', $response->getContent());
    }

    public function testNonBearerSchemeReturns401(): void
    {
        $req      = $this->makeRequest('Basic abc123');
        $response = $this->middleware->handle($req, $this->noop());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('MISSING_TOKEN', $response->getContent());
    }

    public function testEmptyTokenAfterBearerReturns401(): void
    {
        $req      = $this->makeRequest('Bearer ');
        $response = $this->middleware->handle($req, $this->noop());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('MISSING_TOKEN', $response->getContent());
    }

    // -------------------------------------------------------------------------
    // Token validation
    // -------------------------------------------------------------------------

    public function testUnknownTokenReturns401(): void
    {

        $req      = $this->makeRequest('Bearer unknowntoken');
        $response = $this->middleware->handle($req, $this->noop());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('INVALID_TOKEN', $response->getContent());
    }

    public function testExpiredTokenReturns401(): void
    {
        $this->seedToken([
            'id'         => 1,
            'user_id'    => 1,
            'token'      => 'abc',
            'expires_at' => '2000-01-01 00:00:00',
        ]);

        $req      = $this->makeRequest('Bearer abc');
        $response = $this->middleware->handle($req, $this->noop());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('EXPIRED_TOKEN', $response->getContent());
    }

    public function testTokenWithNoExpiryIsNotExpired(): void
    {
        $this->seedToken([
            'id'         => 1,
            'user_id'    => 1,
            'token'      => 'abc',
            'expires_at' => null,
        ]);
        $this->users->method('findById')->willReturn([
            'id'        => 1,
            'is_active' => true,
            'deleted_at' => null,
        ]);

        $req      = $this->makeRequest('Bearer abc');
        $response = $this->middleware->handle($req, $this->noop());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testTokenWithEmptyExpiryIsNotExpired(): void
    {
        $this->seedToken([
            'id'         => 1,
            'user_id'    => 1,
            'token'      => 'abc',
            'expires_at' => '',
        ]);
        $this->users->method('findById')->willReturn([
            'id'         => 1,
            'is_active'  => true,
            'deleted_at' => null,
        ]);

        $req      = $this->makeRequest('Bearer abc');
        $response = $this->middleware->handle($req, $this->noop());

        $this->assertSame(200, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // User validation
    // -------------------------------------------------------------------------

    public function testNullUserReturns401(): void
    {
        $this->seedToken([
            'id' => 1, 'user_id' => 99, 'token' => 'abc', 'expires_at' => null,
        ]);
        $this->users->method('findById')->willReturn(null);

        $req      = $this->makeRequest('Bearer abc');
        $response = $this->middleware->handle($req, $this->noop());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('INACTIVE_USER', $response->getContent());
    }

    public function testInactiveUserReturns401(): void
    {
        $this->seedToken([
            'id' => 1, 'user_id' => 2, 'token' => 'abc', 'expires_at' => null,
        ]);
        $this->users->method('findById')->willReturn([
            'id' => 2, 'is_active' => false, 'deleted_at' => null,
        ]);

        $req      = $this->makeRequest('Bearer abc');
        $response = $this->middleware->handle($req, $this->noop());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('INACTIVE_USER', $response->getContent());
    }

    public function testSoftDeletedUserReturns401(): void
    {
        $this->seedToken([
            'id' => 1, 'user_id' => 2, 'token' => 'abc', 'expires_at' => null,
        ]);
        $this->users->method('findById')->willReturn([
            'id' => 2, 'is_active' => true, 'deleted_at' => '2025-01-01 00:00:00',
        ]);

        $req      = $this->makeRequest('Bearer abc');
        $response = $this->middleware->handle($req, $this->noop());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('INACTIVE_USER', $response->getContent());
    }

    // -------------------------------------------------------------------------
    // Success path
    // -------------------------------------------------------------------------

    public function testValidTokenSetsAuthAttributes(): void
    {
        $tokenRecord = ['id' => 5, 'user_id' => 3, 'token' => 'validtoken', 'expires_at' => null];
        $userRecord  = ['id' => 3, 'is_active' => true, 'deleted_at' => null];

        $this->seedToken($tokenRecord);
        $this->users->method('findById')->willReturn($userRecord);

        $capturedReq = null;
        $next        = function (Request $req) use (&$capturedReq): Response {
            $capturedReq = $req;
            return response()->json(['ok' => true]);
        };

        $req      = $this->makeRequest('Bearer validtoken');
        $response = $this->middleware->handle($req, $next);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($userRecord, $capturedReq->attributes->get('auth_user'));
        $this->assertSame($tokenRecord['id'], $capturedReq->attributes->get('api_token')['id']);
        $this->assertSame($tokenRecord['user_id'], $capturedReq->attributes->get('api_token')['user_id']);
    }

    public function testValidTokenUpdatesLastUsedAt(): void
    {
        $tokenRecord = ['id' => 5, 'user_id' => 3, 'token' => 'validtoken', 'expires_at' => null];
        $userRecord  = ['id' => 3, 'is_active' => true, 'deleted_at' => null];

        $this->seedToken($tokenRecord);
        $this->users->method('findById')->willReturn($userRecord);


        $req = $this->makeRequest('Bearer validtoken');
        $this->middleware->handle($req, $this->noop());

        $this->assertNotNull(ApiToken::findById(5)['last_used_at'] ?? null, 'dernier usage horodaté');
    }

    public function testLastUsedAtUpdateFailureDoesNotBlockRequest(): void
    {
        $tokenRecord = ['id' => 5, 'user_id' => 3, 'token' => 'validtoken', 'expires_at' => null];
        $userRecord  = ['id' => 3, 'is_active' => true, 'deleted_at' => null];

        $this->seedToken($tokenRecord);
        $this->users->method('findById')->willReturn($userRecord);

        $req      = $this->makeRequest('Bearer validtoken');
        $response = $this->middleware->handle($req, $this->noop());

        $this->assertSame(200, $response->getStatusCode());
    }

    /** Enregistre le jeton tel que l'application le stocke : l'empreinte du jeton fourni dans l'en-tête. */
    private function seedToken(array $record): void
    {
        ApiToken::create([
            'id'         => $record['id'],
            'user_id'    => $record['user_id'],
            'token'      => hash_token($record['token']),
            'expires_at' => $record['expires_at'] ?? null,
        ]);
    }
}
