<?php

declare(strict_types=1);

namespace kintai\Http;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request as IlluminateRequest;
use kintai\Http\Middleware\CarriedResponse;
use kintai\Http\Middleware\KintaiKernel;
use kintai\Http\Middleware\ResolveKintaiRoute;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * Action Laravel d'une route Kintai (voir KintaiRoutes) : le contrôleur Kintai, une fois passés les middlewares de la
 * pile de Laravel (KintaiKernel, middlewares globaux, ResolveKintaiRoute, middlewares de la route).
 */
final class KintaiRouteAction
{
    public function __invoke(Application $laravel, IlluminateRequest $request): SymfonyResponse
    {
        [$route, $deferred] = $request->attributes->get(ResolveKintaiRoute::RESOLVED);

        try {
            return CarriedResponse::of(KintaiHttp::app($laravel)->runController(KintaiKernel::request($request), $route, $deferred));
        } catch (Throwable $e) {
            return CarriedResponse::thrown($e);
        }
    }
}
