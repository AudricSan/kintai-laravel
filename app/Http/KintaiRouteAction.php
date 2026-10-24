<?php

declare(strict_types=1);

namespace kintai\Http;

use Illuminate\Foundation\Application;
use Illuminate\Routing\Route as LaravelRoute;
use kintai\Core\Request as KintaiRequest;
use kintai\Core\Route as KintaiRoute;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/** Action des routes Kintai déclarées à Laravel ({@see KintaiRoutes}) : exécute la route Kintai trouvée par Laravel. */
final class KintaiRouteAction
{
    public function __invoke(Application $laravel, LaravelRoute $laravelRoute): SymfonyResponse
    {
        $app     = KintaiHttp::app($laravel);
        $request = new KintaiRequest();
        $route   = $laravelRoute->getAction(KintaiRoutes::ACTION_KEY);

        // Paramètres extraits comme le faisait le routeur Kintai, par l'expression de la route sur le chemin décodé.
        // Laravel sert aussi HEAD par les routes GET ; Kintai non (405) : le routeur Kintai traite alors la requête.
        $match = null;
        if ($route instanceof KintaiRoute && $route->method === $request->method() && preg_match($route->regex, $request->uri(), $m)) {
            $params = [];
            foreach ($route->paramNames as $i => $name) {
                $params[$name] = $m[$i + 1];
            }
            $match = [$route, $params];
        }

        return KintaiBridge::toSymfony($app->handle($request, $match));
    }
}
