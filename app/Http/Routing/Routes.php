<?php

declare(strict_types=1);

namespace App\Http\Routing;

use Illuminate\Routing\Route;
use App\Routing\RouteSegments;

/**
 * Ce que Kintai ajoute aux routes de Laravel : la règle de permission d'une route (->permission()) et la fabrication
 * d'URL dont les paramètres {store} et {employee} deviennent des segments lisibles.
 */
final class Routes
{
    /** Clé de l'action de route qui porte la règle de permission (voir Macros). */
    public const PERMISSION = 'kintai_permission';


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

    /** Chemin d'une route nommée, paramètres typés convertis en segments lisibles (« / » en tête, sans racine). */
    public static function url(string $name, array $params = []): string
    {
        $route = self::byName($name);
        if ($route === null) {
            throw new \InvalidArgumentException("Route [{$name}] not defined.");
        }

        $path = '/' . $route->uri();

        foreach ($params as $key => $value) {
            $type = in_array($key, ['store', 'employee'], true) ? $key : null;
            if ($type !== null) {
                // Paramètre typé : un identifiant devient le segment lisible (slug, numéro d'employé), encodé pour
                // l'URL (所沢東町店 → %E6%89%80…). Une valeur non numérique est prise comme segment déjà prêt.
                $segment = (string) $value;
                if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                    try {
                        $segment = RouteSegments::binder($type)?->segmentFor((int) $value) ?? $segment;
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
}
