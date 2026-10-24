<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use kintai\Core\Exceptions\MethodNotAllowedException;
use kintai\Core\Exceptions\NotFoundException;
use kintai\Core\Router;

final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
    }

    public function testSimpleGetRoute(): void
    {
        $this->router->get('/hello', ['Ctrl', 'index']);

        [$route, $params] = $this->router->dispatch('GET', '/hello');

        $this->assertSame('GET', $route->method);
        $this->assertSame([], $params);
    }

    public function testRouteWithParameters(): void
    {
        $this->router->get('/users/{id}', ['Ctrl', 'show']);

        [$route, $params] = $this->router->dispatch('GET', '/users/42');

        $this->assertSame(['id' => '42'], $params);
    }

    public function testMultipleParameters(): void
    {
        $this->router->get('/stores/{storeId}/shifts/{shiftId}', ['Ctrl', 'show']);

        [$route, $params] = $this->router->dispatch('GET', '/stores/1/shifts/99');

        $this->assertSame(['storeId' => '1', 'shiftId' => '99'], $params);
    }

    public function testWildcardParameterCapturesMultipleSegments(): void
    {
        $this->router->get('/storage/{path*}', ['Ctrl', 'serve']);

        [$route, $params] = $this->router->dispatch('GET', '/storage/img/3/7/photo_1.jpg');

        $this->assertSame(['path' => 'img/3/7/photo_1.jpg'], $params);
    }

    public function testWildcardParameterRequiresAtLeastOneSegment(): void
    {
        $this->router->get('/storage/{path*}', ['Ctrl', 'serve']);

        $this->expectException(NotFoundException::class);
        $this->router->dispatch('GET', '/storage/');
    }

    public function testNamedRouteUrlWithWildcardParameter(): void
    {
        $this->router->get('/storage/{path*}', ['Ctrl', 'serve'], name: 'storage.file');

        $url = $this->router->url('storage.file', ['path' => 'img/1/2/a.jpg']);

        $this->assertSame('/storage/img/1/2/a.jpg', $url);
    }

    public function testGroupWithPrefix(): void
    {
        $this->router->group('/api', function (Router $r) {
            $r->get('/users', ['Ctrl', 'index']);
        });

        [$route, $params] = $this->router->dispatch('GET', '/api/users');

        $this->assertSame('/api/users', $route->pattern);
    }

    public function testNestedGroups(): void
    {
        $this->router->group('/api', function (Router $r) {
            $r->group('/v1', function (Router $r) {
                $r->get('/data', ['Ctrl', 'data']);
            });
        });

        [$route, $params] = $this->router->dispatch('GET', '/api/v1/data');

        $this->assertSame('/api/v1/data', $route->pattern);
    }

    public function testGroupMiddlewareInherited(): void
    {
        $this->router->group('/admin', function (Router $r) {
            $r->get('/dashboard', ['Ctrl', 'dash'], ['Inner']);
        }, ['Outer']);

        [$route] = $this->router->dispatch('GET', '/admin/dashboard');

        $this->assertSame(['Outer', 'Inner'], $route->middleware);
    }

    public function testNamedRoute(): void
    {
        $this->router->get('/users/{id}', ['Ctrl', 'show'], name: 'users.show');

        $url = $this->router->url('users.show', ['id' => 5]);

        $this->assertSame('/users/5', $url);
    }

    public function testNamedRouteThrowsForUndefined(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->router->url('nonexistent');
    }

    public function testNotFoundThrowsException(): void
    {
        $this->router->get('/exists', ['Ctrl', 'index']);

        $this->expectException(NotFoundException::class);
        $this->router->dispatch('GET', '/nope');
    }

    public function testMethodNotAllowedThrowsException(): void
    {
        $this->router->get('/resource', ['Ctrl', 'index']);

        try {
            $this->router->dispatch('POST', '/resource');
            $this->fail('Expected MethodNotAllowedException');
        } catch (MethodNotAllowedException $e) {
            $this->assertContains('GET', $e->allowedMethods);
        }
    }

    public function testAllHttpMethods(): void
    {
        $this->router->get('/r', ['C', 'get']);
        $this->router->post('/r', ['C', 'post']);
        $this->router->put('/r', ['C', 'put']);
        $this->router->patch('/r', ['C', 'patch']);
        $this->router->delete('/r', ['C', 'del']);

        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            [$route] = $this->router->dispatch($method, '/r');
            $this->assertSame($method, $route->method);
        }
    }

    public function testTrailingSlashNormalization(): void
    {
        $this->router->get('/users', ['Ctrl', 'index']);

        [$route] = $this->router->dispatch('GET', '/users/');

        $this->assertSame('/users', $route->pattern);
    }
}
