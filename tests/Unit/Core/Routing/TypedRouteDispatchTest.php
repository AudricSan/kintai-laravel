<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Routing;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use kintai\Core\Repositories\StoreRepositoryInterface;
use kintai\Core\Routing\EmployeeRouteBinder;
use kintai\Core\Routing\RouteBinderRegistry;
use kintai\Core\Routing\StoreRouteBinder;
use kintai\Http\Middleware\BindRouteParameters;
use kintai\Http\Routing\Routes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/** Middleware de test : refuse tout (comme AuthMiddleware pour un visiteur non connecté). */
final class DenyAllTestMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        return redirect()->away('/login');
    }
}

/** Middleware de test : note l'identifiant reçu (comme PermissionMiddleware qui lit le paramètre). */
final class RecordParamTestMiddleware
{
    public static ?string $seen = null;

    public function handle(Request $request, Closure $next): Response
    {
        self::$seen = $request->route('id');
        return $next($request);
    }
}

/** Contrôleur de test : renvoie les paramètres reçus. */
final class EchoParamsTestController
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['id' => $request->route('id'), 'uid' => $request->route('uid')]);
    }
}

/**
 * Routes typées de bout en bout : ->bindSegments(), génération d'URL, résolution avant les middlewares,
 * 301 et 404 rendues seulement après eux (un visiteur refusé n'apprend rien).
 */
final class TypedRouteDispatchTest extends TestCase
{
    private Router $router;

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
        $registry = new RouteBinderRegistry();
        foreach ($binders as $type => $binder) {
            $registry->register($type, fn() => $binder);
        }
        app()->instance(RouteBinderRegistry::class, $registry);

        $this->router = kintai_test_router();
        $this->router->aliasMiddleware('kintai.bind', BindRouteParameters::class);
        $this->router->get('/admin/stores/{id}/edit', [EchoParamsTestController::class, 'show'])
            ->bindSegments(['id' => 'store'])->middleware([RecordParamTestMiddleware::class])->name('stores.edit');
        $this->router->post('/admin/stores/{id}/edit', [EchoParamsTestController::class, 'show'])
            ->bindSegments(['id' => 'store'])->name('stores.update');
        $this->router->get('/admin/stores/{id}/report/{uid}/stats', [EchoParamsTestController::class, 'show'])
            ->bindSegments(['id' => 'store', 'uid' => 'employee'])->name('stores.emp');
        $this->router->get('/private/stores/{id}', [EchoParamsTestController::class, 'show'])
            ->bindSegments(['id' => 'store'])->middleware([DenyAllTestMiddleware::class])->name('private');
        $this->router->get('/files/{path}', [EchoParamsTestController::class, 'show'])->where('path', '.+')->name('files');

        RecordParamTestMiddleware::$seen = null;
    }

    protected function tearDown(): void
    {
        app()->forgetInstance(RouteBinderRegistry::class);
    }

    private function dispatch(string $method, string $uri, string $query = ''): Response
    {
        $request = Request::create($uri . ($query !== '' ? '?' . $query : ''), $method);
        $request->server->set('QUERY_STRING', $query);
        app()->instance('request', $request);

        return $this->router->dispatch($request);
    }

    public function testTypedPlaceholderRecordsItsType(): void
    {
        $this->assertSame(['id' => 'store', 'uid' => 'employee'], Routes::bindings(Routes::byName('stores.emp')));
    }

    public function testUrlGenerationTurnsIdsIntoReadableEncodedSegments(): void
    {
        $this->assertSame('/admin/stores/' . rawurlencode('所沢寿町店') . '/edit', Routes::url('stores.edit', ['id' => 3]));
        $this->assertSame('/admin/stores/' . rawurlencode('所沢寿町店') . '/report/016/stats', Routes::url('stores.emp', ['id' => '3', 'uid' => 18]));
        $this->assertSame('/admin/stores/' . rawurlencode('所沢寿町店') . '/report/id-26/stats', Routes::url('stores.emp', ['id' => 3, 'uid' => 26]));
        $this->assertSame('/files/a/b.pdf', Routes::url('files', ['path' => 'a/b.pdf']), 'paramètres non typés inchangés');
    }

    public function testCanonicalUrlReachesTheControllerWithTheNumericId(): void
    {
        $response = $this->dispatch('GET', '/admin/stores/' . rawurlencode('所沢寿町店') . '/edit');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('{"id":"3","uid":null}', $response->getContent());
        $this->assertSame('3', RecordParamTestMiddleware::$seen, 'les middlewares de route lisent déjà l\'identifiant');
    }

    public function testOldLinkIsRedirectedPermanentlyWithItsQueryString(): void
    {
        $response = $this->dispatch('GET', '/admin/stores/3/report/18/stats', 'period=90');

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('/admin/stores/' . rawurlencode('所沢寿町店') . '/report/016/stats?period=90', $response->headers->get('Location'));
    }

    public function testFormPostedToAnOldLinkIsServedWithoutRedirect(): void
    {
        $response = $this->dispatch('POST', '/admin/stores/3/edit');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('{"id":"3","uid":null}', $response->getContent());
    }

    public function testUnknownSegmentIsA404(): void
    {
        // L'exception traverse la pile de Laravel : son gestionnaire la rend en page 404.
        $this->assertSame(404, $this->dispatch('GET', '/admin/stores/nope/edit')->getStatusCode());
    }

    public function testDeniedVisitorLearnsNeitherTheAliasNorWhetherItExists(): void
    {
        // Ancien lien et segment inconnu : même réponse que le middleware de refus, aucune 301 ni 404.
        foreach (['/private/stores/3', '/private/stores/nope'] as $uri) {
            $response = $this->dispatch('GET', $uri);
            $this->assertSame(302, $response->getStatusCode(), $uri);
            $this->assertSame('/login', $response->headers->get('Location'), $uri);
        }
    }
}
