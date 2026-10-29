<?php

declare(strict_types=1);

namespace kintai\Http;

use Illuminate\Routing\ControllerDispatcher;
use Illuminate\Routing\Route;
use kintai\Http\Middleware\BindRouteParameters;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Appelle le contrôleur d'une route, sauf si BindRouteParameters a mis de côté une redirection 301 ou une 404 (paramètre
 * typé inconnu ou ancien lien) : elle est rendue ici, après les middlewares de la route.
 */
final class KintaiControllerDispatcher extends ControllerDispatcher
{
    public function dispatch(Route $route, $controller, $method)
    {
        $deferred = $this->container->make('request')->attributes->get(BindRouteParameters::DEFERRED);

        if ($deferred instanceof Throwable) {
            throw $deferred;
        }
        if ($deferred instanceof Response) {
            return $deferred;
        }

        return parent::dispatch($route, $controller, $method);
    }
}
