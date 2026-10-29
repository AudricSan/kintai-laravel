<?php

declare(strict_types=1);

namespace kintai\Http\Middleware;

use Closure;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request as IlluminateRequest;
use kintai\Core\NativeCall;
use kintai\Core\Request as KintaiRequest;
use kintai\Core\Response as KintaiResponse;
use kintai\Http\KintaiHttp;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * Un middleware Kintai (MiddlewareInterface, Core ou bundle) placé dans la pile de Laravel : alias « kintai.mw »,
 * paramètres « classe,position » (la position rend chaque entrée unique : Laravel retire les doublons d'une route,
 * alors qu'un même middleware peut y figurer deux fois chez Kintai, en global et sur la route).
 */
final class KintaiMiddleware
{
    public function __construct(private readonly Application $laravel) {}

    public function handle(IlluminateRequest $request, Closure $next, string $class, string $position = '0'): SymfonyResponse
    {
        try {
            $middleware = KintaiHttp::app($this->laravel)->container()->make($class);

            if (NativeCall::acceptsNativeRequest($middleware, 'handle')) {
                return $middleware->handle($request, $next);
            }

            return CarriedResponse::of($middleware->handle(
                KintaiKernel::request($request),
                function (KintaiRequest $kintai) use ($request, $next): KintaiResponse {
                    $request->attributes->set(KintaiKernel::REQUEST, $kintai);
                    return CarriedResponse::unwrap($next($request));
                },
            ));
        } catch (Throwable $e) {
            return CarriedResponse::thrown($e);
        }
    }
}
