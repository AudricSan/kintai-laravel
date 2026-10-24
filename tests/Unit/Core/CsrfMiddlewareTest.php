<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use kintai\Core\Exceptions\HttpException;
use kintai\Core\Middleware\CsrfMiddleware;
use kintai\Core\Request;
use kintai\Core\Response;
use PHPUnit\Framework\TestCase;

/**
 * Jeton CSRF refusé (audit n°8, I8) : avant, une page en dur en français, un lien vers « / » et un exit, y compris
 * pour un appel AJAX. Le cas le plus courant est la page de connexion restée ouverte au-delà de la session.
 */
final class CsrfMiddlewareTest extends TestCase
{
    private array $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $_SESSION = ['_csrf_token' => 'bon-jeton'];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_POST = [];
        $_SESSION = [];
    }

    private function post(string $uri, array $fields, bool $ajax = false): Request
    {
        $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => $uri, 'SCRIPT_NAME' => '/Kintai/index.php'];
        if ($ajax) {
            $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        }
        $_POST = $fields;
        return new Request();
    }

    private function handle(Request $request): Response
    {
        return (new CsrfMiddleware())->handle($request, fn() => Response::html('suite'));
    }

    public function testAValidTokenLetsTheRequestThrough(): void
    {
        $this->assertSame('suite', $this->handle($this->post('/Kintai/profile', ['_token' => 'bon-jeton']))->body());
    }

    public function testAjaxGetsAJson419(): void
    {
        $response = $this->handle($this->post('/Kintai/notifications/read-all', ['_token' => 'périmé'], true));

        $this->assertSame(419, $response->status());
        $this->assertSame('CSRF_TOKEN_MISMATCH', json_decode($response->body(), true)['code']);
    }

    public function testAnExpiredSessionGoesBackToTheLoginPageWithAMessage(): void
    {
        $response = $this->handle($this->post('/Kintai/login', ['_token' => 'périmé']));

        $this->assertSame(302, $response->status());
        $this->assertSame('/Kintai/login?error=expired', $response->headers()['Location'] ?? null, 'sous-dossier conservé');
    }

    public function testAConnectedUserGetsTheTranslated419Page(): void
    {
        $_SESSION['auth_user_id'] = 3;

        try {
            $this->handle($this->post('/Kintai/profile', []));
            $this->fail('419 attendue');
        } catch (HttpException $e) {
            $this->assertSame(419, $e->statusCode);
        }
    }
}
