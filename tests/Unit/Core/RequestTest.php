<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use kintai\Core\Request;

final class RequestTest extends TestCase
{
    private function makeRequest(
        string $method = 'GET',
        string $uri = '/',
        array $get = [],
        array $post = [],
        array $server = [],
        array $cookies = [],
    ): Request {
        $_SERVER = array_merge([
            'REQUEST_METHOD' => $method,
            'REQUEST_URI'    => $uri,
            'SCRIPT_NAME'    => '/index.php',
        ], $server);
        $_GET    = $get;
        $_POST   = $post;
        $_COOKIE = $cookies;
        $_FILES  = [];

        return new Request();
    }

    protected function tearDown(): void
    {
        $_SERVER = [];
        $_GET    = [];
        $_POST   = [];
        $_COOKIE = [];
        $_FILES  = [];
    }

    // -------------------------------------------------------------------------
    // method()
    // -------------------------------------------------------------------------

    public function testMethodReturnsGetByDefault(): void
    {
        $req = $this->makeRequest('GET', '/');
        $this->assertSame('GET', $req->method());
    }

    public function testMethodPost(): void
    {
        $req = $this->makeRequest('POST', '/');
        $this->assertSame('POST', $req->method());
    }

    public function testMethodOverrideViaPostField(): void
    {
        $req = $this->makeRequest('POST', '/', post: ['_method' => 'DELETE']);
        $this->assertSame('DELETE', $req->method());
    }

    public function testMethodOverrideViaHeader(): void
    {
        $req = $this->makeRequest('POST', '/', server: ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'PUT']);
        $this->assertSame('PUT', $req->method());
    }

    public function testMethodOverrideIgnoredForNonPost(): void
    {
        // GET ne peut pas être overridé
        $req = $this->makeRequest('GET', '/', post: ['_method' => 'DELETE']);
        $this->assertSame('GET', $req->method());
    }

    // -------------------------------------------------------------------------
    // uri()
    // -------------------------------------------------------------------------

    public function testUriReturnsNormalizedPath(): void
    {
        $req = $this->makeRequest('GET', '/admin/users');
        $this->assertSame('/admin/users', $req->uri());
    }

    public function testUriStripsQueryString(): void
    {
        $req = $this->makeRequest('GET', '/search?q=hello&page=2');
        $this->assertSame('/search', $req->uri());
    }

    public function testUriNormalizesRootSlash(): void
    {
        $req = $this->makeRequest('GET', '/');
        $this->assertSame('/', $req->uri());
    }

    public function testUriStripsScriptBase(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI'    => '/Kintai/public/admin/users',
            'SCRIPT_NAME'    => '/Kintai/public/index.php',
        ];
        $_GET = $_POST = $_COOKIE = $_FILES = [];
        $req = new Request();
        $this->assertSame('/admin/users', $req->uri());
    }

    // -------------------------------------------------------------------------
    // query() / allQuery()
    // -------------------------------------------------------------------------

    public function testQueryReturnsGetParam(): void
    {
        $req = $this->makeRequest(get: ['page' => '3']);
        $this->assertSame('3', $req->query('page'));
    }

    public function testQueryReturnsDefaultWhenMissing(): void
    {
        $req = $this->makeRequest();
        $this->assertSame('default', $req->query('missing', 'default'));
    }

    public function testAllQueryReturnsAllGetParams(): void
    {
        $req = $this->makeRequest(get: ['a' => '1', 'b' => '2']);
        $this->assertSame(['a' => '1', 'b' => '2'], $req->allQuery());
    }

    // -------------------------------------------------------------------------
    // post() / allPost()
    // -------------------------------------------------------------------------

    public function testPostReturnsPostParam(): void
    {
        $req = $this->makeRequest('POST', '/', post: ['name' => 'Alice']);
        $this->assertSame('Alice', $req->post('name'));
    }

    public function testPostReturnsDefaultWhenMissing(): void
    {
        $req = $this->makeRequest('POST', '/');
        $this->assertNull($req->post('missing'));
    }

    public function testAllPostReturnsAllPostParams(): void
    {
        $req = $this->makeRequest('POST', '/', post: ['x' => '1', 'y' => '2']);
        $this->assertSame(['x' => '1', 'y' => '2'], $req->allPost());
    }

    // -------------------------------------------------------------------------
    // input()
    // -------------------------------------------------------------------------

    public function testInputPrefersPostOverGet(): void
    {
        $req = $this->makeRequest('POST', '/?key=from_get', post: ['key' => 'from_post']);
        $this->assertSame('from_post', $req->input('key'));
    }

    public function testInputFallsBackToGet(): void
    {
        $req = $this->makeRequest(get: ['key' => 'from_get']);
        $this->assertSame('from_get', $req->input('key'));
    }

    public function testInputReturnsDefaultWhenAbsent(): void
    {
        $req = $this->makeRequest();
        $this->assertSame('fallback', $req->input('missing', 'fallback'));
    }

    // -------------------------------------------------------------------------
    // header()
    // -------------------------------------------------------------------------

    public function testHeaderReadsFromServer(): void
    {
        $req = $this->makeRequest(server: ['HTTP_ACCEPT' => 'application/json']);
        $this->assertSame('application/json', $req->header('Accept'));
    }

    public function testHeaderNormalizesName(): void
    {
        $req = $this->makeRequest(server: ['HTTP_X_CUSTOM_HEADER' => 'value']);
        $this->assertSame('value', $req->header('X-Custom-Header'));
    }

    public function testHeaderReturnsNullWhenMissing(): void
    {
        $req = $this->makeRequest();
        $this->assertNull($req->header('X-Missing'));
    }

    // -------------------------------------------------------------------------
    // cookie()
    // -------------------------------------------------------------------------

    public function testCookieReturnsValue(): void
    {
        $req = $this->makeRequest(cookies: ['session' => 'abc123']);
        $this->assertSame('abc123', $req->cookie('session'));
    }

    public function testCookieReturnsDefaultWhenMissing(): void
    {
        $req = $this->makeRequest();
        $this->assertSame('def', $req->cookie('missing', 'def'));
    }

    // -------------------------------------------------------------------------
    // server()
    // -------------------------------------------------------------------------

    public function testServerReturnsValue(): void
    {
        $req = $this->makeRequest(server: ['REMOTE_ADDR' => '192.168.1.1']);
        $this->assertSame('192.168.1.1', $req->server('REMOTE_ADDR'));
    }

    public function testServerReturnsDefault(): void
    {
        $req = $this->makeRequest();
        $this->assertSame('none', $req->server('MISSING_KEY', 'none'));
    }

    // -------------------------------------------------------------------------
    // ip()
    // -------------------------------------------------------------------------

    public function testIpReturnsRemoteAddr(): void
    {
        $req = $this->makeRequest(server: ['REMOTE_ADDR' => '10.0.0.1']);
        $this->assertSame('10.0.0.1', $req->ip());
    }

    public function testIpDefaultsToLoopback(): void
    {
        $req = $this->makeRequest();
        $this->assertSame('127.0.0.1', $req->ip());
    }

    // -------------------------------------------------------------------------
    // isAjax() / wantsJson()
    // -------------------------------------------------------------------------

    public function testIsAjaxWithXmlHttpRequest(): void
    {
        $req = $this->makeRequest(server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $this->assertTrue($req->isAjax());
    }

    public function testIsAjaxWithJsonAccept(): void
    {
        $req = $this->makeRequest(server: ['HTTP_ACCEPT' => 'application/json']);
        $this->assertTrue($req->isAjax());
    }

    public function testIsAjaxFalseByDefault(): void
    {
        $req = $this->makeRequest();
        $this->assertFalse($req->isAjax());
    }

    public function testWantsJsonForApiUri(): void
    {
        $req = $this->makeRequest('GET', '/api/users');
        $this->assertTrue($req->wantsJson());
    }

    public function testWantsJsonFalseForWebUri(): void
    {
        $req = $this->makeRequest('GET', '/admin/users');
        $this->assertFalse($req->wantsJson());
    }

    // -------------------------------------------------------------------------
    // Route params
    // -------------------------------------------------------------------------

    public function testSetAndGetRouteParams(): void
    {
        $req = $this->makeRequest();
        $req->setRouteParams(['id' => '42', 'slug' => 'hello']);

        $this->assertSame('42', $req->param('id'));
        $this->assertSame('hello', $req->param('slug'));
        $this->assertNull($req->param('missing'));
        $this->assertSame(['id' => '42', 'slug' => 'hello'], $req->routeParams());
    }

    public function testParamReturnsDefault(): void
    {
        $req = $this->makeRequest();
        $this->assertSame('fallback', $req->param('x', 'fallback'));
    }

    // -------------------------------------------------------------------------
    // Attributes (middleware)
    // -------------------------------------------------------------------------

    public function testSetAndGetAttribute(): void
    {
        $req = $this->makeRequest();
        $req->setAttribute('auth_user', ['id' => 5, 'email' => 'test@test.com']);

        $attr = $req->getAttribute('auth_user');
        $this->assertSame(5, $attr['id']);
    }

    public function testGetAttributeReturnsDefaultWhenAbsent(): void
    {
        $req = $this->makeRequest();
        $this->assertNull($req->getAttribute('missing'));
        $this->assertSame('default', $req->getAttribute('missing', 'default'));
    }

    public function testAttributeNullTreatedAsAbsent(): void
    {
        // getAttribute uses ??, so null stored is indistinguishable from absent → default wins
        $req = $this->makeRequest();
        $req->setAttribute('managed_store_ids', null);
        $this->assertSame('fallback', $req->getAttribute('managed_store_ids', 'fallback'));
    }
}
