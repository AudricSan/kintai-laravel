<?php

declare(strict_types=1);

namespace App\Http\Routing;

use Illuminate\Routing\Route;
use App\Routing\RouteBinderRegistry;
use App\Routing\RouteParamBinder;

/**
 * Ce que Kintai ajoute aux routes de Laravel : la règle de permission d'une route (->permission()), ses paramètres
 * typés (->bindSegments(['id' => 'store'])), et la fabrication d'URL qui en découle.
 */
final class Routes
{
    /** Clé de l'action de route qui porte la règle de permission (voir Macros). */
    public const PERMISSION = 'kintai_permission';

    /** Clé de l'action de route qui porte les paramètres typés (voir Macros). */
    public const BINDINGS = 'kintai_bindings';

    public static function byName(string $name): ?Route
    {
        $routes = app('router')->getRoutes();
        $route = $routes->getByName($name);
        if ($route === null) {
            // Nom posé après l'ajout de la route : la table des noms est à rafraîchir.
            $routes->refreshNameLookups();
            $route = $routes->getByName($name);
        }

        return $route;
    }

    /** Règle RBAC de la route : clé de permission, tableau de règle, « public », ou null (hors périmètre). */
    public static function permission(?Route $route): string|array|null
    {
        return $route?->getAction(self::PERMISSION);
    }

    /** @return array<string, string> paramètre typé → type ({id:store} donne ['id' => 'store']) */
    public static function bindings(?Route $route): array
    {
        return $route?->getAction(self::BINDINGS) ?? [];
    }

    /** Résolveur d'un type de paramètre (« store », « employee »), ou null si le type est inconnu. */
    public static function binder(string $type): ?RouteParamBinder
    {
        $registry = app(RouteBinderRegistry::class);

        return $registry->has($type) ? $registry->get($type) : null;
    }

    /** Chemin d'une route nommée, paramètres typés convertis en segments lisibles (« / » en tête, sans racine). */
    public static function url(string $name, array $params = []): string
    {
        $route = self::byName($name);
        if ($route === null) {
            throw new \InvalidArgumentException("Route [{$name}] not defined.");
        }

        $path = '/' . $route->uri();
        $bindings = self::bindings($route);

        foreach ($params as $key => $value) {
            $type = $bindings[$key] ?? null;
            if ($type !== null) {
                // Paramètre typé : un identifiant devient le segment lisible (slug, numéro d'employé), encodé pour
                // l'URL (所沢東町店 → %E6%89%80…). Une valeur non numérique est prise comme segment déjà prêt.
                $segment = (string) $value;
                if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                    try {
                        $segment = self::binder($type)?->segmentFor((int) $value) ?? $segment;
                    } catch (\Throwable) {
                        // Base pas encore migrée : l'identifiant nu reste valable (redirigé en 301 ensuite).
                    }
                }
                $path = str_replace("{{$key}}", rawurlencode($segment), $path);
                continue;
            }
            $path = str_replace("{{$key}}", (string) $value, $path);
        }

        return $path;
    }

    /**
     * Chemin d'une route à partir de segments déjà décodés (encodés ici, « / » conservé pour les paramètres qui
     * prennent le reste du chemin). Sert à reconstruire l'URL canonique d'une requête arrivée par un ancien lien.
     *
     * @param array<string, string> $segments
     */
    public static function pathFor(Route $route, array $segments): string
    {
        return '/' . preg_replace_callback('/\{(\w+)\}/', static function (array $m) use ($route, $segments): string {
            $value = (string) ($segments[$m[1]] ?? '');

            return ($route->wheres[$m[1]] ?? null) === '.+'
                ? implode('/', array_map('rawurlencode', explode('/', $value)))
                : rawurlencode($value);
        }, $route->uri());
    }
}
