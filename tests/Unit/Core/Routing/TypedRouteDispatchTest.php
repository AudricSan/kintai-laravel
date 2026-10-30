<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Routing;

use App\Models\RouteSlug;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use App\Repositories\StoreRepositoryInterface;
use App\Routing\EmployeeRouteBinder;
use App\Routing\RouteSegments;
use Illuminate\Routing\Middleware\SubstituteBindings;
use App\Routing\StoreRouteBinder;
use App\Http\Routing\Routes;
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
        self::$seen = (string) $request->route('store');
        return $next($request);
    }
}

/** Contrôleur de test : renvoie les paramètres reçus. */
final class EchoParamsTestController
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['id' => $request->route('store'), 'uid' => $request->route('employee')]);
    }
}

/**
 * Routes typées de bout en bout : Route::bind(), génération d'URL, résolution avant les middlewares,
 * 301 et 404 rendues seulement après eux (un visiteur refusé n'apprend rien).
 */
final class TypedRouteDispatchTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        kintai_test_route_slugs([18 => '016', 26 => null]);
        RouteSlug::setCurrent('store', 3, '所沢寿町店', false);

        $stores = $this->createStub(StoreRepositoryInterface::class);
        $stores->method('findById')->willReturnCallback(fn(int $id) => $id === 3 ? ['store' => 3] : null);

        app()->instance(StoreRouteBinder::class, new StoreRouteBinder($stores));
        app()->instance(EmployeeRouteBinder::class, new EmployeeRouteBinder());

        $this->router = kintai_test_router();
        RouteSegments::register($this->router);
        $this->router->get('/admin/stores/{store}/edit', [EchoParamsTestController::class, 'show'])
            ->middleware([SubstituteBindings::class, RecordParamTestMiddleware::class])->name('stores.edit');
        $this->router->post('/admin/stores/{store}/edit', [EchoParamsTestController::class, 'show'])
            ->middleware([SubstituteBindings::class])->name('stores.update');
        $this->router->get('/admin/stores/{store}/report/{employee}/stats', [EchoParamsTestController::class, 'show'])
            ->middleware([SubstituteBindings::class])->name('stores.emp');
        $this->router->get('/private/stores/{store}', [EchoParamsTestController::class, 'show'])
            ->middleware([DenyAllTestMiddleware::class, SubstituteBindings::class])->name('private');
        $this->router->get('/files/{path}', [EchoParamsTestController::class, 'show'])->where('path', '.+')->name('files');

        RecordParamTestMiddleware::$seen = null;
    }


    private function dispatch(string $method, string $uri, string $query = ''): Response
    {
        $request = Request::create($uri . ($query !== '' ? '?' . $query : ''), $method);
        $request->server->set('QUERY_STRING', $query);
        app()->instance('request', $request);

        return $this->router->dispatch($request);
    }


    public function testUrlGenerationTurnsIdsIntoReadableEncodedSegments(): void
    {
        $this->assertSame('/admin/stores/' . rawurlencode('所沢寿町店') . '/edit', Routes::url('stores.edit', ['store' => 3]));
        $this->assertSame('/admin/stores/' . rawurlencode('所沢寿町店') . '/report/016/stats', Routes::url('stores.emp', ['store' => '3', 'employee' => 18]));
        $this->assertSame('/admin/stores/' . rawurlencode('所沢寿町店') . '/report/id-26/stats', Routes::url('stores.emp', ['store' => 3, 'employee' => 26]));
        $this->assertSame('/files/a/b.pdf', Routes::url('files', ['path' => 'a/b.pdf']), 'paramètres non typés inchangés');
    }

    public function testCanonicalUrlReachesTheControllerWithTheNumericId(): void
    {
        $response = $this->dispatch('GET', '/admin/stores/' . rawurlencode('所沢寿町店') . '/edit');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('{"id":3,"uid":null}', $response->getContent());
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
        $this->assertSame('{"id":3,"uid":null}', $response->getContent());
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
