<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Http\Middleware\SecurityHeadersMiddleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
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

        return Request::capture();
    }

    public function testCspAllowsBlobImagesForClientSidePreviews(): void
    {
        $middleware = new SecurityHeadersMiddleware();

        $response = $middleware->handle($this->makeRequest(), fn(Request $req) => response()->json(['ok' => true]));

        $csp = (string) $response->headers->get("Content-Security-Policy");
        $this->assertMatchesRegularExpression("/img-src[^;]*\bblob:/", $csp);
    }
}
