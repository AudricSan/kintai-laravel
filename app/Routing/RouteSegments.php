<?php

declare(strict_types=1);

namespace App\Routing;

use App\Exceptions\NotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * Paramètres de route lisibles : {store} (alias du magasin) et {employee} (numéro d'employé) sont liés par
 * Route::bind() et remplacés par l'identifiant numérique avant les middlewares qui suivent SubstituteBindings.
 * Un ancien lien (identifiant nu, ancien alias) est redirigé en 301 vers l'URL canonique en GET/HEAD.
 */
final class RouteSegments
{
    /** Nom du paramètre de route → résolveur. */
    private const BINDERS = [
        'store'    => StoreRouteBinder::class,
        'employee' => EmployeeRouteBinder::class,
    ];

    public static function register(Router $router): void
    {
        foreach (array_keys(self::BINDERS) as $param) {
            $router->bind($param, static fn(string $value, Route $route): int => self::bind($param, $value, $route));
        }
    }

    /** Résolveur d'un paramètre de route (« store », « employee »), ou null si le nom n'est pas lié. */
    public static function binder(string $param): ?RouteParamBinder
    {
        return isset(self::BINDERS[$param]) ? app(self::BINDERS[$param]) : null;
    }

    /** Vide les caches des résolveurs (après une écriture qui change un alias dans la même requête). */
    public static function reset(): void
    {
        foreach (self::BINDERS as $class) {
            app($class)->reset();
        }
    }

    private static function bind(string $param, string $value, Route $route): int
    {
        $binder = self::binder($param);
        $bound  = $binder->resolve($value);
        if ($bound === null) {
            throw new NotFoundException("No [{$param}] matches [{$value}].");
        }

        $request = app('request');
        if (!$bound->canonical && in_array($request->method(), ['GET', 'HEAD'], true)) {
            // URL canonique : le segment de chaque paramètre lié de la route est remplacé (un ancien lien peut en
            // cumuler plusieurs), pour une seule redirection.
            $segments = explode('/', ltrim($request->getPathInfo(), '/'));
            foreach (explode('/', $route->uri()) as $index => $placeholder) {
                $name = trim($placeholder, '{}');
                if ($placeholder === '{' . $name . '}' && isset(self::BINDERS[$name], $segments[$index])) {
                    $other = $name === $param ? $bound : self::binder($name)->resolve(rawurldecode($segments[$index]));
                    if ($other !== null) {
                        $segments[$index] = rawurlencode(self::binder($name)->segmentFor($other->id));
                    }
                }
            }
            $query = $request->getQueryString();

            throw new HttpResponseException(redirect()->away(
                base_url() . '/' . implode('/', $segments) . ($query !== null && $query !== '' ? '?' . $query : ''),
                301,
            ));
        }

        return $bound->id;
    }
}
