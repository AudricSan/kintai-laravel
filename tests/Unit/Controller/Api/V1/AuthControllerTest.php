<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Controller\Api\V1;

use kintai\Core\Auth\AuthService;
use kintai\Core\Exceptions\NotFoundException;
use kintai\Core\Repositories\ApiTokenRepositoryInterface;
use kintai\Core\Repositories\RememberTokenRepositoryInterface;
use kintai\Core\Repositories\RoleAssignmentRepositoryInterface;
use kintai\Core\Repositories\RoleRepositoryInterface;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Repositories\StoreUserRepositoryInterface;
use kintai\Core\Repositories\UserRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Services\AuditLogger;
use kintai\UI\Controller\Api\V1\AuthController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class AuthControllerTest extends TestCase
{
    private UserRepositoryInterface&Stub     $users;
    private ApiTokenRepositoryInterface&Stub $tokens;
    private AuthService $auth;
    private ?AuthController $controller = null;

    protected function setUp(): void
    {
        $storeUsers = $this->createStub(StoreUserRepositoryInterface::class);
        $stores     = $this->createStub(StoreRepositoryInterface::class);
        $roles           = $this->createStub(RoleRepositoryInterface::class);
        $roleAssignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $this->users  = $this->createStub(UserRepositoryInterface::class);
        $this->tokens = $this->createStub(ApiTokenRepositoryInterface::class);

        $this->auth       = new AuthService($this->users, $storeUsers, $stores, $roles, $roleAssignments, $this->createStub(RememberTokenRepositoryInterface::class));
    }

    /** Objet testé, construit à la première utilisation : un test peut d'abord remplacer un bouchon par un mock. */
    private function controller(): AuthController
    {
        if ($this->controller === null) {
            $this->controller = new AuthController($this->auth, $this->tokens, $this->users, new AuditLogger());
        }

        return $this->controller;
    }

    // -------------------------------------------------------------------------
    // ping
    // -------------------------------------------------------------------------

    public function testPingReturnsOk(): void
    {
        $req      = $this->makeRequest('GET', '/api/v1/ping');
        $response = $this->controller()->ping($req);

        $this->assertSame(200, $response->status());
        $data = json_decode($response->body(), true);
        $this->assertSame('ok', $data['status']);
        $this->assertSame('v1', $data['version']);
    }

    // -------------------------------------------------------------------------
    // login — validation
    // -------------------------------------------------------------------------

    public function testLoginMissingCredentialsReturns422(): void
    {
        $req      = $this->makeRequest('POST', '/api/v1/auth/login', json: ['password' => 'x']);
        $response = $this->controller()->login($req);

        $this->assertSame(422, $response->status());
        $data = json_decode($response->body(), true);
        $this->assertSame('MISSING_CREDENTIALS', $data['code']);
    }

    public function testLoginInvalidEmailReturns401(): void
    {
        $this->users->method('findByEmail')->willReturn(null);

        $req      = $this->makeRequest('POST', '/api/v1/auth/login', json: [
            'email'    => 'nobody@example.com',
            'password' => 'wrong',
        ]);
        $response = $this->controller()->login($req);

        $this->assertSame(401, $response->status());
        $data = json_decode($response->body(), true);
        $this->assertSame('INVALID_CREDENTIALS', $data['code']);
    }

    public function testFailedLoginFlagsTheRequestForTheThrottle(): void
    {
        $this->users->method('findByEmail')->willReturn(null);

        $req = $this->makeRequest('POST', '/api/v1/auth/login', json: ['email' => 'nobody@example.com', 'password' => bin2hex(random_bytes(6))]);
        $this->controller()->login($req);

        // Lu par LoginThrottleMiddleware : seuls les échecs comptent.
        $this->assertTrue($req->getAttribute('auth_failed'));
    }

    public function testMissingCredentialsDoNotCountAsAFailedLogin(): void
    {
        $req = $this->makeRequest('POST', '/api/v1/auth/login', json: ['password' => bin2hex(random_bytes(6))]);
        $response = $this->controller()->login($req);

        $this->assertSame(422, $response->status());
        $this->assertNull($req->getAttribute('auth_failed'));
    }

    public function testLoginInvalidEmployeeCodeReturns401(): void
    {
        $this->users->method('findByEmployeeCode')->willReturn(null);

        $req      = $this->makeRequest('POST', '/api/v1/auth/login', json: [
            'employee_code' => 'EMP001',
            'store_code'    => 'SHOP1',
            'password'      => 'wrong',
        ]);
        $response = $this->controller()->login($req);

        $this->assertSame(401, $response->status());
    }

    public function testLoginSuccessReturnsToken(): void
    {
        $hash = password_hash('secret-passphrase', PASSWORD_BCRYPT);
        $this->users->method('findByEmail')->willReturn([
            'id'            => 7,
            'email'         => 'alice@example.com',
            'password_hash' => $hash,
            'is_active'     => true,
            'deleted_at'    => null,
        ]);
        // Comme en base : findById() et findByEmail() renvoient la même ligne, hash compris
        // (la session est liée à l'empreinte de ce hash).
        $this->users->method('findById')->willReturn([
            'id'            => 7,
            'email'         => 'alice@example.com',
            'password_hash' => $hash,
            'is_active'     => true,
            'deleted_at'    => null,
        ]);
        $this->tokens->method('save')->willReturn([
            'id'         => 1,
            'user_id'    => 7,
            'token'      => 'rawtoken',
            'expires_at' => null,
        ]);

        $req      = $this->makeRequest('POST', '/api/v1/auth/login', json: [
            'email'    => 'alice@example.com',
            'password' => 'secret-passphrase',
        ]);
        $response = $this->controller()->login($req);

        $this->assertSame(201, $response->status());
        $data = json_decode($response->body(), true);
        $this->assertArrayHasKey('token', $data);
        $this->assertArrayHasKey('user', $data);
        $this->assertArrayNotHasKey('password_hash', $data['user']);
    }

    /**
     * Audit du 01/10/2026 : l'API délivrait un jeton avec le mot de passe par défaut « 0000 ». Le web ne fait
     * que rappeler de le changer (PasswordReminderMiddleware) ; un jeton API, accès durable, reste refusé.
     *
     * @return array<string, array{string}>
     */
    public static function weakPasswords(): array
    {
        return ['mot de passe par défaut' => ['0000'], 'mot de passe court' => ['abc1234']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('weakPasswords')]
    public function testWeakPasswordGetsNoTokenAndMustBeChangedOnTheWeb(string $password): void
    {
        $this->tokens = $this->createMock(ApiTokenRepositoryInterface::class);
        $row = [
            'id' => 9, 'email' => 'weak@example.com', 'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]),
            'is_active' => true, 'deleted_at' => null,
        ];
        $this->users->method('findByEmail')->willReturn($row);
        $this->users->method('findById')->willReturn($row);
        $this->tokens->expects($this->never())->method('save');

        $req      = $this->makeRequest('POST', '/api/v1/auth/login', json: ['email' => 'weak@example.com', 'password' => $password]);
        $response = $this->controller()->login($req);

        $this->assertSame(403, $response->status());
        $this->assertSame('PASSWORD_CHANGE_REQUIRED', json_decode($response->body(), true)['code']);
        // Identifiants corrects : pas un échec pour la limitation des tentatives.
        $this->assertNull($req->getAttribute('auth_failed'));
        // Aucune session ne doit rester ouverte.
        $this->assertNull($this->auth->user());
    }

    public function testDefaultPasswordByEmployeeCodeGetsNoToken(): void
    {
        $row = [
            'id' => 9, 'email' => null, 'password_hash' => password_hash('0000', PASSWORD_BCRYPT, ['cost' => 4]),
            'is_active' => true, 'deleted_at' => null,
        ];
        $storeUsers = $this->createStub(StoreUserRepositoryInterface::class);
        $storeUsers->method('findMembership')->willReturn(['id' => 1]);
        $stores = $this->createStub(StoreRepositoryInterface::class);
        $stores->method('findByCode')->willReturn(['id' => 3]);
        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findByEmployeeCode')->willReturn($row);
        $users->method('findById')->willReturn($row);
        $roleAssignments = $this->createStub(RoleAssignmentRepositoryInterface::class);
        $roleAssignments->method('findByUser')->willReturn([]);
        $tokens = $this->createMock(ApiTokenRepositoryInterface::class);
        $tokens->expects($this->never())->method('save');

        $auth       = new AuthService($users, $storeUsers, $stores, $this->createStub(RoleRepositoryInterface::class), $roleAssignments, $this->createStub(RememberTokenRepositoryInterface::class));
        $controller = new AuthController($auth, $tokens, $users, new AuditLogger());

        $response = $controller->login($this->makeRequest('POST', '/api/v1/auth/login', json: [
            'employee_code' => '057', 'store_code' => '21836', 'password' => '0000',
        ]));

        $this->assertSame(403, $response->status());
    }

    public function testLoginTokenLengthIs64Hex(): void
    {
        $hash = password_hash('long-enough-pass', PASSWORD_BCRYPT);
        $this->users->method('findByEmail')->willReturn([
            'id'            => 1,
            'email'         => 'x@x.com',
            'password_hash' => $hash,
            'is_active'     => true,
            'deleted_at'    => null,
        ]);
        $this->users->method('findById')->willReturn([
            'id' => 1, 'email' => 'x@x.com', 'password_hash' => $hash, 'is_active' => true, 'deleted_at' => null,
        ]);

        $capturedToken = null;
        $this->tokens->method('save')->willReturnCallback(function ($data) use (&$capturedToken) {
            $capturedToken = $data['token'];
            return array_merge($data, ['id' => 1]);
        });

        $req = $this->makeRequest('POST', '/api/v1/auth/login', json: [
            'email' => 'x@x.com', 'password' => 'long-enough-pass',
        ]);
        $this->controller()->login($req);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $capturedToken);
    }

    // -------------------------------------------------------------------------
    // logout
    // -------------------------------------------------------------------------

    public function testLogoutDeletesToken(): void
    {
        $this->tokens = $this->createMock(ApiTokenRepositoryInterface::class);
        $this->tokens->expects($this->once())->method('delete')->with(42);

        $req = $this->makeRequest('POST', '/api/v1/auth/logout');
        $req->setAttribute('api_token', ['id' => 42]);

        $response = $this->controller()->logout($req);
        $this->assertSame(204, $response->status());
    }

    public function testLogoutWithoutTokenAttributeIsGraceful(): void
    {
        $this->tokens = $this->createMock(ApiTokenRepositoryInterface::class);
        $this->tokens->expects($this->never())->method('delete');

        $req      = $this->makeRequest('POST', '/api/v1/auth/logout');
        $response = $this->controller()->logout($req);

        $this->assertSame(204, $response->status());
    }

    // -------------------------------------------------------------------------
    // me
    // -------------------------------------------------------------------------

    public function testMeReturnsUserWithoutPasswordHash(): void
    {
        $user = ['id' => 1, 'email' => 'a@b.com', 'password_hash' => 'secret'];

        $req = $this->makeRequest('GET', '/api/v1/auth/me');
        $req->setAttribute('auth_user', $user);

        $response = $this->controller()->me($req);

        $this->assertSame(200, $response->status());
        $data = json_decode($response->body(), true);
        $this->assertArrayNotHasKey('password_hash', $data);
        $this->assertSame('a@b.com', $data['email']);
    }

    // -------------------------------------------------------------------------
    // listTokens
    // -------------------------------------------------------------------------

    public function testListTokensHidesRawToken(): void
    {
        $this->tokens->method('findByUserId')->willReturn([
            ['id' => 1, 'user_id' => 3, 'token' => 'secret1', 'name' => 'CI'],
            ['id' => 2, 'user_id' => 3, 'token' => 'secret2', 'name' => 'App'],
        ]);

        $req = $this->makeRequest('GET', '/api/v1/auth/tokens');
        $req->setAttribute('auth_user', ['id' => 3]);

        $response = $this->controller()->listTokens($req);

        $this->assertSame(200, $response->status());
        $data = json_decode($response->body(), true);
        $this->assertCount(2, $data);
        foreach ($data as $row) {
            $this->assertArrayNotHasKey('token', $row);
        }
    }

    // -------------------------------------------------------------------------
    // revokeToken
    // -------------------------------------------------------------------------

    public function testRevokeTokenDeletesWhenOwnerMatches(): void
    {
        $this->tokens = $this->createMock(ApiTokenRepositoryInterface::class);
        $this->tokens->method('findById')->willReturn(['id' => 5, 'user_id' => 3]);
        $this->tokens->expects($this->once())->method('delete')->with(5);

        $req = $this->makeRequest('DELETE', '/api/v1/auth/tokens/5');
        $req->setRouteParams(['id' => '5']);
        $req->setAttribute('auth_user', ['id' => 3]);

        $response = $this->controller()->revokeToken($req);
        $this->assertSame(204, $response->status());
    }

    public function testRevokeTokenThrowsWhenOwnerMismatch(): void
    {
        $this->tokens->method('findById')->willReturn(['id' => 5, 'user_id' => 99]);

        $req = $this->makeRequest('DELETE', '/api/v1/auth/tokens/5');
        $req->setRouteParams(['id' => '5']);
        $req->setAttribute('auth_user', ['id' => 3]);

        $this->expectException(NotFoundException::class);
        $this->controller()->revokeToken($req);
    }

    public function testRevokeTokenThrowsWhenTokenNotFound(): void
    {
        $this->tokens->method('findById')->willReturn(null);

        $req = $this->makeRequest('DELETE', '/api/v1/auth/tokens/999');
        $req->setRouteParams(['id' => '999']);
        $req->setAttribute('auth_user', ['id' => 3]);

        $this->expectException(NotFoundException::class);
        $this->controller()->revokeToken($req);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeRequest(string $method, string $uri, array $json = []): Request
    {
        $_SERVER = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI'    => $uri,
            'SCRIPT_NAME'    => '/index.php',
        ];
        $_GET    = [];
        $_POST   = [];
        $_COOKIE = [];
        $_FILES  = [];

        if ($json !== []) {
            // On simule un corps JSON via php://input n'est pas possible dans un test unitaire.
            // On réutilise la réflexion pour injecter le corps JSON directement.
            $req = new Request();
            $ref = new \ReflectionProperty(Request::class, 'jsonBody');
            $ref->setAccessible(true);
            $ref->setValue($req, $json);
            return $req;
        }

        return new Request();
    }

    protected function tearDown(): void
    {
        $_SERVER = [];
        $_GET    = [];
        $_POST   = [];
    }

    // -------------------------------------------------------------------------
    // Échéance des jetons (audit du 13/10/2026, M9)
    // -------------------------------------------------------------------------

    /** Prépare une connexion réussie et renvoie la date d'expiration transmise au dépôt. */
    private function loginAndCaptureExpiry(array $extra): array
    {
        $hash = password_hash('secret-passphrase', PASSWORD_BCRYPT, ['cost' => 4]);
        $row  = ['id' => 7, 'email' => 'alice@example.com', 'password_hash' => $hash, 'is_active' => true, 'deleted_at' => null];
        $this->users->method('findByEmail')->willReturn($row);
        $this->users->method('findById')->willReturn($row);

        $saved = null;
        $this->tokens->method('save')->willReturnCallback(function (array $d) use (&$saved): array {
            $saved = $d;
            return $d + ['id' => 1];
        });

        $req = $this->makeRequest('POST', '/api/v1/auth/login', json: ['email' => 'alice@example.com', 'password' => 'secret-passphrase'] + $extra);
        $response = $this->controller()->login($req);

        return [$response, $saved];
    }

    public function testTokenWithoutExpiresInExpiresAfterNinetyDays(): void
    {
        [$response, $saved] = $this->loginAndCaptureExpiry([]);

        $this->assertSame(201, $response->status());
        $this->assertNotNull($saved['expires_at']);
        $this->assertEqualsWithDelta(time() + AuthController::TOKEN_TTL, strtotime($saved['expires_at']), 5);
        $this->assertSame($saved['expires_at'], json_decode($response->body(), true)['expires_at']);
    }

    public function testShorterExpiresInIsHonoured(): void
    {
        [, $saved] = $this->loginAndCaptureExpiry(['expires_in' => 3600]);

        $this->assertEqualsWithDelta(time() + 3600, strtotime($saved['expires_at']), 5);
    }

    public function testExpiresInAboveTheCeilingIsCapped(): void
    {
        [, $saved] = $this->loginAndCaptureExpiry(['expires_in' => 10 * 365 * 86400]);

        $this->assertEqualsWithDelta(time() + AuthController::TOKEN_TTL, strtotime($saved['expires_at']), 5);
    }

    /** @return array<string, array{mixed}> */
    public static function invalidExpiresIn(): array
    {
        return ['zero' => [0], 'negatif' => [-60], 'texte' => ['bientot'], 'decimal' => [1.5], 'booleen' => [true], 'null' => [null]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidExpiresIn')]
    public function testInvalidExpiresInIsRejectedWithoutIssuingAToken(mixed $expiresIn): void
    {
        $this->tokens = $this->createMock(ApiTokenRepositoryInterface::class);
        $this->tokens->expects($this->never())->method('save');

        $req = $this->makeRequest('POST', '/api/v1/auth/login', json: ['email' => 'alice@example.com', 'password' => 'x', 'expires_in' => $expiresIn]);
        $response = $this->controller()->login($req);

        $this->assertSame(422, $response->status());
        $this->assertNull($req->getAttribute('auth_failed'));
    }
}
