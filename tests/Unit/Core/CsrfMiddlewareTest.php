<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use kintai\Core\Exceptions\HttpException;
use kintai\Core\Middleware\CsrfMiddleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
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
        session()->flush();
        session()->put(['_token' => 'bon-jeton']);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_POST = [];
        session()->flush();
    }

    private function post(string $uri, array $fields, bool $ajax = false): Request
    {
        $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => $uri, 'SCRIPT_NAME' => '/Kintai/index.php'];
        if ($ajax) {
            $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        }
        $_POST = $fields;
        return Request::capture();
    }

    private function handle(Request $request): Response
    {
        return (new CsrfMiddleware())->handle($request, fn() => response('suite'));
    }

    public function testAValidTokenLetsTheRequestThrough(): void
    {
        $this->assertSame('suite', $this->handle($this->post('/Kintai/profile', ['_token' => 'bon-jeton']))->getContent());
    }

    public function testAjaxGetsAJson419(): void
    {
        $response = $this->handle($this->post('/Kintai/notifications/read-all', ['_token' => 'périmé'], true));

        $this->assertSame(419, $response->getStatusCode());
        $this->assertSame('CSRF_TOKEN_MISMATCH', json_decode($response->getContent(), true)['code']);
    }

    public function testAnExpiredSessionGoesBackToTheLoginPageWithAMessage(): void
    {
        $response = $this->handle($this->post('/Kintai/login', ['_token' => 'périmé']));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/Kintai/login?error=expired', $response->headers->get('Location'), 'sous-dossier conservé');
    }

    public function testAConnectedUserGetsTheTranslated419Page(): void
    {
        session()->put('auth_user_id', 3);

        try {
            $this->handle($this->post('/Kintai/profile', []));
            $this->fail('419 attendue');
        } catch (HttpException $e) {
            $this->assertSame(419, $e->statusCode);
        }
    }
}
