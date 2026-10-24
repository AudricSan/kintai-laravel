<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use kintai\Core\Request;
use PHPUnit\Framework\TestCase;

/**
 * Sous Apache, l'en-tête Authorization n'arrive pas dans $_SERVER : toute requête d'API à jeton répondait
 * MISSING_TOKEN (constaté pendant l'audit du 13/10/2026 sur l'instance XAMPP). Request le reprend de la
 * variable posée par public/.htaccess ou des en-têtes bruts.
 */
final class RequestAuthorizationHeaderTest extends TestCase
{
    public function testExistingValueIsKept(): void
    {
        $server = Request::withAuthorizationHeader(
            ['HTTP_AUTHORIZATION' => 'Bearer from-server', 'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer other'],
            ['Authorization' => 'Bearer raw'],
        );

        $this->assertSame('Bearer from-server', $server['HTTP_AUTHORIZATION']);
    }

    public function testRecoveredFromTheRewriteVariable(): void
    {
        $server = Request::withAuthorizationHeader(['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer rewritten'], []);

        $this->assertSame('Bearer rewritten', $server['HTTP_AUTHORIZATION']);
    }

    public function testRecoveredFromRawHeadersWhateverTheCase(): void
    {
        $server = Request::withAuthorizationHeader(['HTTP_HOST' => 'kintai.test'], ['Host' => 'kintai.test', 'authorization' => 'Bearer raw']);

        $this->assertSame('Bearer raw', $server['HTTP_AUTHORIZATION']);
        $this->assertSame('kintai.test', $server['HTTP_HOST']);
    }

    public function testNothingAddedWithoutAnyAuthorization(): void
    {
        $server = Request::withAuthorizationHeader(['HTTP_HOST' => 'kintai.test'], ['Host' => 'kintai.test']);

        $this->assertArrayNotHasKey('HTTP_AUTHORIZATION', $server);
    }

    public function testTheRequestExposesTheRecoveredHeader(): void
    {
        $previous = $_SERVER;
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/v1/auth/me', 'SCRIPT_NAME' => '/index.php', 'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer abc'];
        try {
            $this->assertSame('Bearer abc', (new Request())->header('Authorization'));
        } finally {
            $_SERVER = $previous;
        }
    }
}
