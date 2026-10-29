<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core;

use kintai\Core\Auth\PermissionCatalog;
use kintai\Core\Container;
use kintai\Core\Middleware\ApiPermissionMiddleware;
use kintai\Core\Middleware\OwnerOnlyMiddleware;
use kintai\Core\Middleware\PermissionMiddleware;
use Illuminate\Routing\Router;
use kintai\Http\Routing\RouteRegistrar;
use kintai\Http\Routing\Routes;
use PHPUnit\Framework\TestCase;

/**
 * Invariants de la déclaration RBAC désormais portée par chaque route
 * (Route::$permission, paramètre `permission:` de Router::get/post/...) :
 * toute route passée sous PermissionMiddleware/ApiPermissionMiddleware doit
 * déclarer soit une clé de PermissionCatalog (éventuellement enrobée dans une
 * règle ['perm' => ..., 'self'/'membership'/'store_param' => ...]), soit la
 * chaîne 'public' pour une exemption volontaire — jamais rien du tout. C'est
 * la même invariant que l'ancien PermissionMapsTest (qui comparait
 * config/permissions.php / config/api-permissions.php à deux allowlists
 * maintenues à la main), mais elle ne peut plus avoir d'angle mort : une
 * route qui n'a jamais reçu le middleware n'est de toute façon jamais
 * dispatchée par le pipeline sans contrôle — voir CHANGELOG pour l'historique
 * du bundle Messaging, resté hors RBAC pendant des mois avec l'ancien système
 * à deux fichiers séparés.
 */
final class PermissionMapsTest extends TestCase
{
    /**
     * Routes du Core + de tous les bundles.
     *
     * Les bundles ne vivent plus dans app/Bundles/ (ce sont des dépôts séparés installés dans
     * storage/bundles/, absent de la CI) : l'ancien glob sur app/Bundles/*\/routes.php ne renvoyait
     * plus rien, et aucune route de bundle n'était donc vérifiée. Les fixtures
     * tests/Fixtures/bundles/*\/routes.php, versionnées dans ce dépôt, sont la copie que la CI peut
     * lire ; le glob historique est conservé pour un bundle tiers déposé à la main.
     *
     * @return list<object>
     */
    private function allRoutes(): array
    {
        $root = dirname(__DIR__, 3);
        $laravel = kintai_test_load_routes();
        $router = new RouteRegistrar($laravel);
        $container = new Container();

        foreach (array_merge(
            glob($root . '/app/Bundles/*/routes.php') ?: [],
            glob($root . '/tests/Fixtures/bundles/*/routes.php') ?: [],
        ) as $bundleRoutes) {
            require $bundleRoutes;
        }

        return $this->describe($laravel);
    }

    /**
     * Les routes de Laravel sous une forme simple, une entrée par méthode HTTP (HEAD exclu).
     *
     * @return list<object{method: string, pattern: string, middleware: list<string>, name: ?string, permission: string|array|null}>
     */
    private function describe(Router $router): array
    {
        $described = [];
        foreach ($router->getRoutes()->getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                $described[] = (object) [
                    'method'     => $method,
                    'pattern'    => '/' . $route->uri(),
                    'middleware' => array_values(array_filter($route->gatherMiddleware(), static fn($m) => $m !== 'kintai.bind')),
                    'name'       => $route->getName(),
                    'permission' => Routes::permission($route),
                ];
            }
        }

        return $described;
    }

    /** Routes du Core seul, pour mesurer ce qu'apportent les bundles. */
    private function coreRouteCount(): int
    {
        return count($this->describe(kintai_test_load_routes()));
    }

    /**
     * Routes qui devraient être sous RBAC mais ne le sont pas : toute route /admin doit passer par
     * PermissionMiddleware (ou OwnerOnlyMiddleware, pour les pages réservées à l'Owner), toute route
     * /api/v1 par ApiPermissionMiddleware, sauf exemptions documentées.
     *
     * @param list<object> $routes
     * @return string[]
     */
    private function routesOutsideRbac(array $routes): array
    {
        // Exemptions volontaires de l'API : points d'entrée publics (ping, connexion, protégée par son
        // propre limiteur) et messagerie, en libre-service, dont chaque contrôleur vérifie que
        // l'appelant participe au fil (voir CHANGELOG, « Messaging stays self-service »).
        $apiExemptExact = ['/api/v1/ping', '/api/v1/auth/login'];
        $apiExemptPrefix = ['/api/v1/messages'];

        $outside = [];
        foreach ($routes as $route) {
            $mw = $route->middleware;
            if (str_starts_with($route->pattern, '/admin')
                && !in_array(PermissionMiddleware::class, $mw, true)
                && !in_array(OwnerOnlyMiddleware::class, $mw, true)) {
                $outside[] = "{$route->method} {$route->pattern}";
            }
            if (str_starts_with($route->pattern, '/api/v1')
                && !in_array(ApiPermissionMiddleware::class, $mw, true)
                && !in_array($route->pattern, $apiExemptExact, true)) {
                $exempt = false;
                foreach ($apiExemptPrefix as $prefix) {
                    $exempt = $exempt || str_starts_with($route->pattern, $prefix);
                }
                if (!$exempt) {
                    $outside[] = "{$route->method} {$route->pattern}";
                }
            }
        }

        return $outside;
    }

    public function testEveryPermissionMiddlewareRouteDeclaresAPermission(): void
    {
        foreach ($this->allRoutes() as $route) {
            if (!in_array(PermissionMiddleware::class, $route->middleware, true)) {
                continue;
            }
            $this->assertNotNull($route->name, 'Route sans nom sous PermissionMiddleware : ' . $route->pattern);
            $this->assertNotNull(
                $route->permission,
                "Route '{$route->name}' passe par PermissionMiddleware mais ne déclare pas de " .
                "paramètre permission: (une clé de PermissionCatalog, ou 'public' si l'absence " .
                'est volontaire).'
            );
        }
    }

    public function testEveryApiPermissionMiddlewareRouteDeclaresAPermission(): void
    {
        foreach ($this->allRoutes() as $route) {
            if (!in_array(ApiPermissionMiddleware::class, $route->middleware, true)) {
                continue;
            }
            $this->assertNotNull($route->name, 'Route sans nom sous ApiPermissionMiddleware : ' . $route->pattern);
            $this->assertNotNull(
                $route->permission,
                "Route '{$route->name}' passe par ApiPermissionMiddleware mais ne déclare pas de " .
                "paramètre permission: (une clé de PermissionCatalog, ou 'public' si l'absence " .
                'est volontaire).'
            );
        }
    }

    public function testEveryDeclaredPermissionOnlyReferencesCatalogKeysAndValidRuleShapes(): void
    {
        foreach ($this->allRoutes() as $route) {
            $rule = $route->permission;
            if ($rule === null || $rule === 'public') {
                continue;
            }

            $isWebRoute = in_array(PermissionMiddleware::class, $route->middleware, true);
            $allowedOptions = $isWebRoute
                ? ['perm', 'membership', 'store_param']
                : ['perm', 'self', 'membership'];

            if (is_array($rule)) {
                $this->assertArrayHasKey('perm', $rule, "Route {$route->name} : une règle tableau doit avoir une entrée 'perm'.");
                $key = $rule['perm'];
                foreach (array_keys($rule) as $option) {
                    $this->assertContains($option, $allowedOptions, "Route {$route->name} : option de règle inconnue '$option'.");
                }
            } else {
                $this->assertIsString($rule);
                $key = $rule;
            }

            $this->assertTrue(
                PermissionCatalog::exists($key),
                "Route {$route->name} : la clé '$key' n'existe pas dans PermissionCatalog."
            );
        }
    }

    public function testManagerDefaultsAreAllValidCatalogKeys(): void
    {
        foreach (PermissionCatalog::MANAGER_DEFAULTS as $key) {
            $this->assertTrue(
                PermissionCatalog::exists($key),
                "MANAGER_DEFAULTS contient une clé inconnue du catalogue : '$key'."
            );
        }
    }

    public function testBundleRoutesAreActuallyPartOfTheInspectedRoutes(): void
    {
        // Garde-fou contre le retour de l'angle mort : si le chargement des routes de bundles casse (dossier déplacé,
        // glob vide), les tests ci-dessus passeraient sans les vérifier. Tous les bundles ont quitté le Core et
        // vérifient leurs propres routes dans leur dépôt ; une copie de bundle remise sous tests/Fixtures/bundles/
        // devrait apporter au moins trois routes.
        $fixtures = glob(dirname(__DIR__, 3) . '/tests/Fixtures/bundles/*/routes.php') ?: [];
        $this->assertGreaterThanOrEqual(
            $this->coreRouteCount() + 3 * count($fixtures),
            count($this->allRoutes()),
            'Les routes des bundles ne sont plus chargées : les invariants RBAC ne les vérifieraient plus.'
        );
    }

    public function testEveryAdminAndApiRouteIsGatedByRbac(): void
    {
        $this->assertSame(
            [],
            $this->routesOutsideRbac($this->allRoutes()),
            'Ces routes /admin ou /api/v1 échappent au RBAC : ajouter PermissionMiddleware (web) ou '
            . 'ApiPermissionMiddleware (API) avec une déclaration permission:, ou les exempter ici avec une justification.'
        );
    }

    public function testTheRbacGateCheckDetectsAnUnprotectedBundleRoute(): void
    {
        // Témoin positif : sans lui, un test qui ne trouve jamais rien ne prouverait rien.
        $router = kintai_test_registrar();
        $router->get('/admin/some-bundle/list', ['Foo', 'bar'], middleware: [], name: 'admin.some_bundle.list');
        $router->post('/admin/some-bundle/save', ['Foo', 'bar'], middleware: [PermissionMiddleware::class], name: 'admin.some_bundle.save', permission: 'employees.view');
        $router->get('/api/v1/some-bundle', ['Foo', 'bar'], middleware: [], name: 'api.v1.some_bundle');
        $router->get('/api/v1/messages/threads', ['Foo', 'bar'], middleware: [], name: 'api.v1.messages.threads');

        $outside = $this->routesOutsideRbac($this->describe(app('router')));

        $this->assertSame(['GET /admin/some-bundle/list', 'GET /api/v1/some-bundle'], $outside);
    }
}
