<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Middleware;

use Closure;
use kintai\Core\Exceptions\HttpException;
use kintai\Core\Middleware\LoginThrottleMiddleware;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Security\AttemptCounter;
use PHPUnit\Framework\TestCase;

/**
 * Seuls les ÉCHECS comptent, par IP (large : IP partagée dans un magasin) et par compte visé
 * (indépendamment de l'IP). Une connexion réussie remet le compteur du compte à zéro.
 */
final class LoginThrottleMiddlewareTest extends TestCase
{
    private string $dir;
    private LoginThrottleMiddleware $middleware;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kintai-throttle-' . bin2hex(random_bytes(4));
        $this->middleware = new LoginThrottleMiddleware(new AttemptCounter($this->dir));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        $_POST = [];
    }

    /** @param array<string,string> $post @param array<string,mixed> $json */
    private function request(string $ip, array $post = [], array $json = []): Request
    {
        $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/login', 'SCRIPT_NAME' => '/index.php', 'REMOTE_ADDR' => $ip];
        $_GET = [];
        $_POST = $post;
        $_COOKIE = [];
        $_FILES = [];
        $req = new Request();
        $ref = new \ReflectionProperty(Request::class, 'jsonBody');
        $ref->setAccessible(true);
        $ref->setValue($req, $json);
        return $req;
    }

    /** Le contrôleur simulé : signale un échec (ou non) comme le fait le vrai contrôleur de connexion. */
    private function controller(bool $fails): Closure
    {
        return static function (Request $r) use ($fails): Response {
            if ($fails) {
                $r->setAttribute('auth_failed', true);
                return Response::redirect('/login?error=1');
            }
            return Response::redirect('/employee');
        };
    }

    private function attempt(Request $req, bool $fails): Response
    {
        return $this->middleware->handle($req, $this->controller($fails));
    }

    private function assertBlocked(Request $req): HttpException
    {
        try {
            $this->attempt($req, false);
        } catch (HttpException $e) {
            $this->assertSame(429, $e->statusCode);
            return $e;
        }
        $this->fail('La requête aurait dû être refusée (429).');
    }

    public function testSuccessfulLoginsAreNeverCounted(): void
    {
        // 50 employés d'un même magasin (même IP) se connectent à la suite : aucun blocage.
        for ($i = 0; $i < 50; $i++) {
            $res = $this->attempt($this->request('10.0.0.1', ['email' => "emp{$i}@shop.test"]), false);
            $this->assertSame(302, $res->status());
        }
    }

    public function testAccountIsBlockedAfterTooManyFailuresWhateverTheIp(): void
    {
        // Un attaquant qui change d'IP à chaque essai n'échappe pas à la limite par compte.
        for ($i = 0; $i < LoginThrottleMiddleware::ACCOUNT_MAX_FAILURES; $i++) {
            $this->attempt($this->request("203.0.113.{$i}", ['email' => 'victim@shop.test']), true);
        }

        $this->assertBlocked($this->request('198.51.100.7', ['email' => 'victim@shop.test']));
    }

    public function testAnotherAccountIsNotAffectedByTheBlock(): void
    {
        for ($i = 0; $i < LoginThrottleMiddleware::ACCOUNT_MAX_FAILURES; $i++) {
            $this->attempt($this->request("203.0.113.{$i}", ['email' => 'victim@shop.test']), true);
        }

        $res = $this->attempt($this->request('198.51.100.7', ['email' => 'someone.else@shop.test']), false);
        $this->assertSame(302, $res->status());
    }

    public function testIpIsBlockedAfterTooManyFailuresAcrossDifferentAccounts(): void
    {
        for ($i = 0; $i < LoginThrottleMiddleware::IP_MAX_FAILURES; $i++) {
            $this->attempt($this->request('203.0.113.9', ['email' => "guess{$i}@shop.test"]), true);
        }

        $this->assertBlocked($this->request('203.0.113.9', ['email' => 'fresh@shop.test']));
    }

    public function testSuccessfulLoginResetsTheAccountCounter(): void
    {
        for ($i = 0; $i < LoginThrottleMiddleware::ACCOUNT_MAX_FAILURES - 1; $i++) {
            $this->attempt($this->request('10.0.0.1', ['email' => 'emp@shop.test']), true);
        }
        // Le bon mot de passe finit par être saisi.
        $this->attempt($this->request('10.0.0.1', ['email' => 'emp@shop.test']), false);

        // Le compteur est reparti de zéro : un nouvel échec ne bloque pas.
        $this->attempt($this->request('10.0.0.1', ['email' => 'emp@shop.test']), true);
        $res = $this->attempt($this->request('10.0.0.1', ['email' => 'emp@shop.test']), false);
        $this->assertSame(302, $res->status());
    }

    public function testBlockedResponseCarriesRetryAfter(): void
    {
        for ($i = 0; $i < LoginThrottleMiddleware::ACCOUNT_MAX_FAILURES; $i++) {
            $this->attempt($this->request('10.0.0.1', ['email' => 'victim@shop.test']), true);
        }

        $e = $this->assertBlocked($this->request('10.0.0.1', ['email' => 'victim@shop.test']));

        $this->assertArrayHasKey('Retry-After', $e->headers);
        $this->assertGreaterThan(0, (int) $e->headers['Retry-After']);
        $this->assertLessThanOrEqual(LoginThrottleMiddleware::WINDOW, (int) $e->headers['Retry-After']);
    }

    public function testEmailIsNormalisedSoCaseAndSpacesCannotDodgeTheLimit(): void
    {
        $variants = ['Victim@Shop.test', ' victim@shop.test ', 'VICTIM@SHOP.TEST'];
        for ($i = 0; $i < LoginThrottleMiddleware::ACCOUNT_MAX_FAILURES; $i++) {
            $this->attempt($this->request("203.0.113.{$i}", ['email' => $variants[$i % 3]]), true);
        }

        $this->assertBlocked($this->request('198.51.100.7', ['email' => 'victim@shop.test']));
    }

    public function testEmployeeCodeLoginIsThrottledPerCodeAndStore(): void
    {
        for ($i = 0; $i < LoginThrottleMiddleware::ACCOUNT_MAX_FAILURES; $i++) {
            $this->attempt($this->request("203.0.113.{$i}", ['login_mode' => 'code', 'employee_code' => 'e042', 'store_code' => 'tokyo']), true);
        }

        // Même compte, casse différente : bloqué.
        $this->assertBlocked($this->request('198.51.100.7', ['login_mode' => 'code', 'employee_code' => 'E042', 'store_code' => 'TOKYO']));
        // Même code employé dans un autre magasin : c'est un autre compte.
        $res = $this->attempt($this->request('198.51.100.7', ['login_mode' => 'code', 'employee_code' => 'E042', 'store_code' => 'OSAKA']), false);
        $this->assertSame(302, $res->status());
    }

    public function testApiJsonBodyIdentifiesTheAccount(): void
    {
        for ($i = 0; $i < LoginThrottleMiddleware::ACCOUNT_MAX_FAILURES; $i++) {
            $this->attempt($this->request("203.0.113.{$i}", [], ['email' => 'api-victim@shop.test', 'password' => bin2hex(random_bytes(6))]), true);
        }

        $this->assertBlocked($this->request('198.51.100.7', [], ['email' => 'api-victim@shop.test']));
    }

    public function testRequestWithoutAnyIdentifierOnlyCountsAgainstTheIp(): void
    {
        for ($i = 0; $i < LoginThrottleMiddleware::IP_MAX_FAILURES; $i++) {
            $this->attempt($this->request('203.0.113.9'), true);
        }

        $this->assertBlocked($this->request('203.0.113.9'));
        // Une autre IP n'est pas concernée.
        $res = $this->attempt($this->request('203.0.113.10'), false);
        $this->assertSame(302, $res->status());
    }

    public function testFailuresBelowTheThresholdAreNotBlocked(): void
    {
        for ($i = 0; $i < LoginThrottleMiddleware::ACCOUNT_MAX_FAILURES - 1; $i++) {
            $this->attempt($this->request('10.0.0.1', ['email' => 'clumsy@shop.test']), true);
        }

        $res = $this->attempt($this->request('10.0.0.1', ['email' => 'clumsy@shop.test']), false);
        $this->assertSame(302, $res->status());
    }
}
