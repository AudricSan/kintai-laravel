<?php

declare(strict_types=1);

namespace kintai\Http;

use Illuminate\Routing\Router as LaravelRouter;
use kintai\Core\Route as KintaiRoute;
use kintai\Core\Router as KintaiRouter;

/**
 * Déclare au routeur de Laravel les routes Kintai (routes/kintai.php et routes des bundles).
 *
 * Chaque route garde sa méthode, son chemin et son nom ; son action ({@see KintaiRouteAction}) exécute la route Kintai
 * correspondante (middlewares globaux, paramètres typés, middlewares de route, contrôleur). L'ordre est conservé :
 * comme chez Kintai, la première route déclarée qui correspond l'emporte.
 */
final class KintaiRoutes
{
    /** Clé de l'action Laravel qui porte la route Kintai. */
    public const ACTION_KEY = 'kintai.route';

    /**
     * @param list<string> $globalMiddleware middlewares Kintai appliqués à toute requête (Application::globalMiddleware())
     */
    public static function register(KintaiRouter $kintai, LaravelRouter $laravel, array $globalMiddleware = []): void
    {
        $taken = [];
        foreach ($kintai->routes() as $route) {
            $uri = self::uri($route);
            // Deux routes Kintai au même chemin (ou qui ne diffèrent que par le nom d'un paramètre) : la première
            // gagne chez Kintai, alors que Laravel garderait la dernière.
            $key = $route->method . ' ' . preg_replace('/\{\w+\}/', '{}', $uri);
            if (isset($taken[$key])) {
                continue;
            }
            $taken[$key] = true;

            $laravelRoute = $laravel->addRoute([$route->method], $uri, [
                'uses'           => KintaiRouteAction::class,
                self::ACTION_KEY => $route,
            ]);
            foreach (self::wildcards($route) as $name) {
                $laravelRoute->where($name, '.+');
            }
            if ($route->name !== null) {
                $laravelRoute->name($route->name);
            }
            $laravelRoute->middleware(self::middleware($globalMiddleware, $route->middleware));
        }
    }

    /** Chemin au format Laravel : {id:store} devient {id}, {path*} devient {path}. */
    /**
     * Pile de Laravel d'une route Kintai, dans l'ordre où Kintai l'exécutait : KintaiKernel (rendu des erreurs, journal
     * d'accès), les middlewares globaux, ResolveKintaiRoute (route et paramètres), puis ceux de la route.
     *
     * @param list<string> $global
     * @param list<string> $route
     * @return list<string>
     */
    public static function middleware(array $global, array $route = [], bool $resolve = true): array
    {
        $stack = ['kintai.kernel'];
        $position = 0;
        foreach ($global as $class) {
            $stack[] = 'kintai.mw:' . $class . ',' . $position++;
        }
        if ($resolve) {
            $stack[] = 'kintai.resolve';
        }
        foreach ($route as $class) {
            $stack[] = 'kintai.mw:' . $class . ',' . $position++;
        }
        return $stack;
    }

    public static function uri(KintaiRoute $route): string
    {
        return (string) preg_replace('/\{(\w+)(?::\w+)?\*?\}/', '{$1}', $route->pattern);
    }

    /** @return string[] paramètres {x*}, qui prennent tout le reste du chemin */
    private static function wildcards(KintaiRoute $route): array
    {
        preg_match_all('/\{(\w+)(?::\w+)?\*\}/', $route->pattern, $m);

        return $m[1];
    }
}
