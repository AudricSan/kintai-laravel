<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Exceptions\NotFoundException;
use App\Http\Routing\Routes;
use Symfony\Component\HttpFoundation\Response;

/**
 * Paramètres de route typés (alias « kintai.bind », ajouté en tête des middlewares de la route par
 * Route::bindSegments()) : traduit les segments lisibles ({id:store}, {uid:employee}) en identifiants, AVANT les
 * middlewares de la route (les contrôles d'accès les lisent par $request->route()).
 *
 * Un ancien lien (identifiant nu, alias historique) est redirigé en 301 vers l'URL canonique en GET/HEAD ; les autres
 * méthodes (formulaires POST) sont servies sans redirection. La redirection, ou la 404 d'un segment inconnu (son
 * paramètre vaut alors « 0 »), n'est rendue qu'APRÈS les middlewares, juste avant le contrôleur
 * (KintaiControllerDispatcher) : un visiteur non connecté ou sans droit ne doit apprendre ni le nom d'un magasin ni
 * l'existence d'un numéro d'employé.
 */
final class BindRouteParameters
{
    /** Attribut de la requête : la réponse (301) ou l'exception (404) à rendre à la place du contrôleur. */
    public const DEFERRED = 'kintai.deferred';

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $bindings = Routes::bindings($route);
        if ($bindings === []) {
            return $next($request);
        }

        $params   = $route->parameters();
        $segments = $params;
        $redirect = false;

        foreach ($bindings as $name => $type) {
            $binder = Routes::binder($type);
            if ($binder === null || !isset($params[$name])) {
                continue;
            }
            $bound = $binder->resolve($params[$name]);
            if ($bound === null) {
                $request->attributes->set(self::DEFERRED, new NotFoundException("No [{$type}] matches [{$params[$name]}]."));
                $route->setParameter($name, '0');

                return $next($request);
            }
            $redirect        = $redirect || !$bound->canonical;
            $segments[$name] = $binder->segmentFor($bound->id);
            $route->setParameter($name, (string) $bound->id);
        }

        if ($redirect && in_array($request->method(), ['GET', 'HEAD'], true)) {
            $query = (string) $request->server->get('QUERY_STRING', '');
            $request->attributes->set(self::DEFERRED, redirect()->away(
                base_url() . Routes::pathFor($route, $segments) . ($query !== '' ? '?' . $query : ''),
                301,
            ));
        }

        return $next($request);
    }
}
