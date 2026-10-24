<?php

declare(strict_types=1);

namespace kintai\Core\Middleware;

use Closure;
use kintai\Core\Container;
use kintai\Core\Request;
use kintai\Core\Response;

final class MiddlewarePipeline
{
    public function __construct(private readonly Container $container) {}

    /**
     * Run the request through a stack of middleware, then call $core.
     *
     * @param Request $request
     * @param string[] $middlewareClasses
     * @param Closure(Request): Response $core
     */
    public function run(Request $request, array $middlewareClasses, Closure $core): Response
    {
        $pipeline = array_reduce(
            array_reverse($middlewareClasses),
            function (Closure $next, string $class) {
                return function (Request $request) use ($next, $class) {
                    /** @var MiddlewareInterface $middleware */
                    $middleware = $this->container->make($class);
                    return $middleware->handle($request, $next);
                };
            },
            $core,
        );

        return $pipeline($request);
    }
}
