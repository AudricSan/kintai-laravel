<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Http;

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Router as LaravelRouter;
use kintai\Core\Route as KintaiRoute;
use kintai\Core\Router as KintaiRouter;
use kintai\Http\KintaiRoutes;
use PHPUnit\Framework\TestCase;

final class KintaiRoutesTest extends TestCase
{
    private LaravelRouter $laravel;

    protected function setUp(): void
    {
        $kintai = new KintaiRouter();
        $kintai->get('/users/create', ['UsersController', 'create'], name: 'users.create');
        $kintai->get('/users/{id}', ['UsersController', 'show']);
        $kintai->post('/users/{id}', ['UsersController', 'update']);
        // Même chemin, nom de paramètre différent : la première déclarée gagne, comme dans le routeur Kintai.
        $kintai->get('/stores/{id:store}', ['StoresController', 'first']);
        $kintai->get('/stores/{slug}', ['StoresController', 'second']);
        $kintai->get('/storage/{path*}', ['StorageController', 'serve']);

        $this->laravel = new LaravelRouter(new Dispatcher(), new Container());
        KintaiRoutes::register($kintai, $this->laravel);
        $this->laravel->getRoutes()->refreshNameLookups();
    }

    private function handlerFor(string $method, string $uri): array
    {
        $route = $this->laravel->getRoutes()->match(Request::create($uri, $method));
        $kintai = $route->getAction(KintaiRoutes::ACTION_KEY);
        $this->assertInstanceOf(KintaiRoute::class, $kintai);

        return $kintai->handler;
    }

    public function testTheFirstDeclaredRouteWins(): void
    {
        $this->assertSame(['UsersController', 'create'], $this->handlerFor('GET', '/users/create'));
        $this->assertSame(['UsersController', 'show'], $this->handlerFor('GET', '/users/12'));
        $this->assertSame(['StoresController', 'first'], $this->handlerFor('GET', '/stores/tokyo'));
    }

    public function testTheMethodIsKept(): void
    {
        $this->assertSame(['UsersController', 'update'], $this->handlerFor('POST', '/users/12'));
    }

    public function testAWildcardTakesTheRestOfThePath(): void
    {
        $this->assertSame(['StorageController', 'serve'], $this->handlerFor('GET', '/storage/uploads/a/b.png'));
    }

    public function testNamesAndTypedParametersAreTranslated(): void
    {
        $this->assertSame('users/create', $this->laravel->getRoutes()->getByName('users.create')?->uri());
        $this->assertSame('/stores/{id}', KintaiRoutes::uri(new KintaiRoute('GET', '/stores/{id:store}', '', [], ['id'])));
        $this->assertSame('/storage/{path}', KintaiRoutes::uri(new KintaiRoute('GET', '/storage/{path*}', '', [], ['path'])));
    }
}
