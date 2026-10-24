<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Routing;

use Closure;
use kintai\Core\Application;
use kintai\Core\Container;
use kintai\Core\Exceptions\NotFoundException;
use kintai\Core\Middleware\MiddlewareInterface;
use kintai\Core\Middleware\MiddlewarePipeline;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Router;
use kintai\Core\Routing\EmployeeRouteBinder;
use kintai\Core\Routing\StoreRouteBinder;
use PHPUnit\Framework\TestCase;

/** Middleware de test : refuse tout (comme AuthMiddleware pour un visiteur non connecté). */
final class DenyAllTestMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next): Response
    {
        return Response::redirect('/login');
    }
}

/** Middleware de test : note l'identifiant reçu (comme PermissionMiddleware qui lit le paramètre). */
final class RecordParamTestMiddleware implements MiddlewareInterface
{
    public static ?string $seen = null;

    public function handle(Request $request, Closure $next): Response
    {
        self::$seen = $request->param('id');
        return $next($request);
    }
}

/** Contrôleur de test : renvoie les paramètres reçus. */
final class EchoParamsTestController
{
    public function show(Request $request): Response
    {
        return Response::json(['id' => $request->param('id'), 'uid' => $request->param('uid')]);
    }
}

/**
 * Routes typées de bout en bout : syntaxe {param:type}, génération d'URL, résolution avant les middlewares,
 * 301 et 404 rendues seulement après eux (un visiteur refusé n'apprend rien).
 */
final class TypedRouteDispatchTest extends TestCase
{
    private Router $router;
    private Application $app;

    protected function setUp(): void
    {
        $repo = new InMemoryRouteSlugRepository([18 => '016', 26 => null]);
        $repo->setCurrent('store', 3, '所沢寿町店', false);

        $stores = $this->createStub(StoreRepositoryInterface::class);
        $stores->method('findById')->willReturnCallback(fn(int $id) => $id === 3 ? ['id' => 3] : null);

        $binders = [
            'store'    => new StoreRouteBinder($repo, $stores),
            'employee' => new EmployeeRouteBinder($repo),
        ];

        $this->router = new Router();
        $this->router->setBinderResolver(fn(string $type) => $binders[$type] ?? null);
        $this->router->get('/admin/stores/{id:store}/edit', [EchoParamsTestController::class, 'show'], [RecordParamTestMiddleware::class], 'stores.edit');
        $this->router->post('/admin/stores/{id:store}/edit', [EchoParamsTestController::class, 'show'], [], 'stores.update');
        $this->router->get('/admin/stores/{id:store}/report/{uid:employee}/stats', [EchoParamsTestController::class, 'show'], [], 'stores.emp');
        $this->router->get('/private/stores/{id:store}', [EchoParamsTestController::class, 'show'], [DenyAllTestMiddleware::class], 'private');
        $this->router->get('/files/{path*}', [EchoParamsTestController::class, 'show'], [], 'files');

        $container = new Container();
        $this->app = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
        foreach (['container' => $container, 'router' => $this->router, 'pipeline' => new MiddlewarePipeline($container)] as $prop => $value) {
            (new \ReflectionProperty(Application::class, $prop))->setValue($this->app, $value);
        }
        RecordParamTestMiddleware::$seen = null;
    }

    protected function tearDown(): void
    {
        $_SERVER = [];
    }

    private function dispatch(string $method, string $uri, string $query = ''): Response
    {
        $_SERVER = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri . ($query !== '' ? '?' . $query : ''), 'SCRIPT_NAME' => '/index.php', 'QUERY_STRING' => $query];
        $_GET = $_POST = $_COOKIE = $_FILES = [];

        return (new \ReflectionMethod(Application::class, 'dispatch'))->invoke($this->app, new Request());
    }

    private function location(Response $response): string
    {
        return (string) ((fn() => $this->headers['Location'] ?? '')->call($response));
    }

    public function testTypedPlaceholderCompilesAndRecordsItsType(): void
    {
        $route = $this->router->routeByName('stores.emp');

        $this->assertSame(['id', 'uid'], $route->paramNames);
        $this->assertSame(['id' => 'store', 'uid' => 'employee'], $route->bindings);
    }

    public function testUrlGenerationTurnsIdsIntoReadableEncodedSegments(): void
    {
        $this->assertSame('/admin/stores/' . rawurlencode('所沢寿町店') . '/edit', $this->router->url('stores.edit', ['id' => 3]));
        $this->assertSame('/admin/stores/' . rawurlencode('所沢寿町店') . '/report/016/stats', $this->router->url('stores.emp', ['id' => '3', 'uid' => 18]));
        $this->assertSame('/admin/stores/' . rawurlencode('所沢寿町店') . '/report/id-26/stats', $this->router->url('stores.emp', ['id' => 3, 'uid' => 26]));
        $this->assertSame('/files/a/b.pdf', $this->router->url('files', ['path' => 'a/b.pdf']), 'paramètres non typés inchangés');
    }

    public function testCanonicalUrlReachesTheControllerWithTheNumericId(): void
    {
        $response = $this->dispatch('GET', '/admin/stores/' . rawurlencode('所沢寿町店') . '/edit');

        $this->assertSame(200, $response->status());
        $this->assertSame('{"id":"3","uid":null}', $response->body());
        $this->assertSame('3', RecordParamTestMiddleware::$seen, 'les middlewares de route lisent déjà l\'identifiant');
    }

    public function testOldLinkIsRedirectedPermanentlyWithItsQueryString(): void
    {
        $response = $this->dispatch('GET', '/admin/stores/3/report/18/stats', 'period=90');

        $this->assertSame(301, $response->status());
        $this->assertSame('/admin/stores/' . rawurlencode('所沢寿町店') . '/report/016/stats?period=90', $this->location($response));
    }

    public function testFormPostedToAnOldLinkIsServedWithoutRedirect(): void
    {
        $response = $this->dispatch('POST', '/admin/stores/3/edit');

        $this->assertSame(200, $response->status());
        $this->assertSame('{"id":"3","uid":null}', $response->body());
    }

    public function testUnknownSegmentIsA404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->dispatch('GET', '/admin/stores/nope/edit');
    }

    public function testDeniedVisitorLearnsNeitherTheAliasNorWhetherItExists(): void
    {
        // Ancien lien et segment inconnu : même réponse que le middleware de refus, aucune 301 ni 404.
        foreach (['/private/stores/3', '/private/stores/nope'] as $uri) {
            $response = $this->dispatch('GET', $uri);
            $this->assertSame(302, $response->status(), $uri);
            $this->assertSame('/login', $this->location($response), $uri);
        }
    }
}
