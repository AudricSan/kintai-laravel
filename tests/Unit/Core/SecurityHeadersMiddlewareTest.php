<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use kintai\Core\Middleware\SecurityHeadersMiddleware;
use kintai\Core\Request;
use kintai\Core\Response;
use PHPUnit\Framework\TestCase;

final class SecurityHeadersMiddlewareTest extends TestCase
{
    private function makeRequest(string $uri = '/', string $method = 'GET'): Request
    {
        $_SERVER = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI'    => $uri,
            'SCRIPT_NAME'    => '/index.php',
        ];
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];

        return new Request();
    }

    public function testCspAllowsBlobImagesForClientSidePreviews(): void
    {
        $middleware = new SecurityHeadersMiddleware();

        $response = $middleware->handle($this->makeRequest(), fn(Request $req) => Response::json(['ok' => true]));

        $headers = (fn() => $this->headers)->call($response);
        $csp = $headers['Content-Security-Policy'] ?? '';
        $this->assertMatchesRegularExpression("/img-src[^;]*\bblob:/", $csp);
    }
}
