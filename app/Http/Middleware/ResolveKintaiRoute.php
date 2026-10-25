<?php

declare(strict_types=1);

namespace kintai\Http\Middleware;

use Closure;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request as IlluminateRequest;
use Illuminate\Routing\Route as LaravelRoute;
use kintai\Core\Route as KintaiRoute;
use kintai\Http\KintaiHttp;
use kintai\Http\KintaiRoutes;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * Entre les middlewares globaux et ceux de la route (alias « kintai.resolve ») : trouve la route Kintai de la requête
 * et prépare ses paramètres, comme Application::resolveRoute(). Les paramètres sont extraits comme le faisait le
 * routeur Kintai, par l'expression de la route sur le chemin décodé.
 *
 * Quand la route Kintai n'est pas celle que Laravel a choisie (HEAD, que Laravel sert par les routes GET et Kintai non ;
 * route attrape-tout de kintai\Http\KintaiBridge), le routeur Kintai décide : 404, 405, ou sa route, dont les
 * middlewares et le contrôleur passent alors par sa propre pile.
 */
final class ResolveKintaiRoute
{
    /** Attribut de la requête Laravel : [route Kintai, réponse différée] pour KintaiRouteAction. */
    public const RESOLVED = 'kintai.resolved';

    public function __construct(private readonly Application $laravel) {}

    public function handle(IlluminateRequest $request, Closure $next): SymfonyResponse
    {
        $app = KintaiHttp::app($this->laravel);
        $kintai = KintaiKernel::request($request);
        $laravelRoute = $request->route();
        $expected = $laravelRoute instanceof LaravelRoute ? $laravelRoute->getAction(KintaiRoutes::ACTION_KEY) : null;

        try {
            $match = null;
            if ($expected instanceof KintaiRoute && $expected->method === $kintai->method() && preg_match($expected->regex, $kintai->uri(), $m)) {
                $params = [];
                foreach ($expected->paramNames as $i => $name) {
                    $params[$name] = $m[$i + 1];
                }
                $match = [$expected, $params];
            }

            [$route, $deferred] = $app->resolveRoute($kintai, $match);
            if ($route !== $expected) {
                return CarriedResponse::of($app->runRoute($kintai, $route, $deferred));
            }
        } catch (Throwable $e) {
            return CarriedResponse::thrown($e);
        }

        $request->attributes->set(self::RESOLVED, [$route, $deferred]);

        return $next($request);
    }
}
