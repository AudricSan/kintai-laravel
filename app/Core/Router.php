<?php

declare(strict_types=1);

namespace kintai\Core;

use kintai\Core\Exceptions\MethodNotAllowedException;
use kintai\Core\Exceptions\NotFoundException;

final class Router
{
    /** @var Route[] */
    private array $routes = [];

    /** @var array<string, Route> Named routes */
    private array $named = [];

    private string $groupPrefix = '';

    /** @var string[] */
    private array $groupMiddleware = [];

    /**
     * Fournit le binder d'un type de paramètre ({id:store} → « store »), ou null si le type est inconnu.
     * Branché par Application ; absent (tests du routeur seul), les paramètres typés restent bruts.
     *
     * @var (\Closure(string): ?\kintai\Core\Routing\RouteParamBinder)|null
     */
    private ?\Closure $binderResolver = null;

    /** @param callable(string): ?\kintai\Core\Routing\RouteParamBinder $resolver */
    public function setBinderResolver(callable $resolver): void
    {
        $this->binderResolver = \Closure::fromCallable($resolver);
    }

    public function binderFor(string $type): ?\kintai\Core\Routing\RouteParamBinder
    {
        return $this->binderResolver !== null ? ($this->binderResolver)($type) : null;
    }

    public function get(string $pattern, array $handler, array $middleware = [], ?string $name = null, string|array|null $permission = null): self
    {
        return $this->addRoute('GET', $pattern, $handler, $middleware, $name, $permission);
    }

    public function post(string $pattern, array $handler, array $middleware = [], ?string $name = null, string|array|null $permission = null): self
    {
        return $this->addRoute('POST', $pattern, $handler, $middleware, $name, $permission);
    }

    public function put(string $pattern, array $handler, array $middleware = [], ?string $name = null, string|array|null $permission = null): self
    {
        return $this->addRoute('PUT', $pattern, $handler, $middleware, $name, $permission);
    }

    public function patch(string $pattern, array $handler, array $middleware = [], ?string $name = null, string|array|null $permission = null): self
    {
        return $this->addRoute('PATCH', $pattern, $handler, $middleware, $name, $permission);
    }

    public function delete(string $pattern, array $handler, array $middleware = [], ?string $name = null, string|array|null $permission = null): self
    {
        return $this->addRoute('DELETE', $pattern, $handler, $middleware, $name, $permission);
    }

    /**
     * @param string $prefix URL prefix for the group
     * @param callable(Router): void $callback
     * @param string[] $middleware Middleware applied to all routes in the group
     */
    public function group(string $prefix, callable $callback, array $middleware = []): self
    {
        $previousPrefix = $this->groupPrefix;
        $previousMiddleware = $this->groupMiddleware;

        $this->groupPrefix = $previousPrefix . $prefix;
        $this->groupMiddleware = array_merge($previousMiddleware, $middleware);

        $callback($this);

        $this->groupPrefix = $previousPrefix;
        $this->groupMiddleware = $previousMiddleware;

        return $this;
    }

    /**
     * Resolve a request to a matched Route + extracted params.
     *
     * @return array{Route, array<string, string>}
     * @throws NotFoundException|MethodNotAllowedException
     */
    public function dispatch(string $method, string $uri): array
    {
        $method = strtoupper($method);
        // Normalize: ensure leading slash, strip trailing
        $uri = '/' . trim($uri, '/');

        $allowedMethods = [];

        foreach ($this->routes as $route) {
            if (preg_match($route->regex, $uri, $matches)) {
                if ($route->method !== $method) {
                    $allowedMethods[] = $route->method;
                    continue;
                }

                $params = [];
                foreach ($route->paramNames as $i => $name) {
                    $params[$name] = $matches[$i + 1];
                }

                return [$route, $params];
            }
        }

        if ($allowedMethods !== []) {
            throw new MethodNotAllowedException(array_unique($allowedMethods));
        }

        throw new NotFoundException("No route matches [{$method} {$uri}].");
    }

    /**
     * Generate a URL for a named route.
     */
    public function url(string $name, array $params = []): string
    {
        if (!isset($this->named[$name])) {
            throw new \InvalidArgumentException("Route [{$name}] not defined.");
        }

        $route   = $this->named[$name];
        $pattern = $route->pattern;

        foreach ($params as $key => $value) {
            $type = $route->bindings[$key] ?? null;
            if ($type !== null) {
                // Paramètre typé : un identifiant devient le segment lisible (slug, numéro d'employé), encodé
                // pour l'URL (所沢東町店 → %E6%89%80…). Une valeur non numérique est prise comme segment déjà prêt.
                $binder  = $this->binderFor($type);
                $segment = $binder !== null && (is_int($value) || (is_string($value) && ctype_digit($value)))
                    ? $binder->segmentFor((int) $value)
                    : (string) $value;
                $pattern = str_replace("{{$key}:{$type}}", rawurlencode($segment), $pattern);
                continue;
            }
            $pattern = str_replace(["{{$key}}", "{{$key}*}"], (string) $value, $pattern);
        }

        return $pattern;
    }

    /**
     * Chemin d'une route à partir de segments déjà décodés (encodés ici, « / » conservé pour {x*}). Sert à
     * reconstruire l'URL canonique d'une requête arrivée par un ancien lien.
     *
     * @param array<string, string> $segments
     */
    public function pathFor(Route $route, array $segments): string
    {
        return (string) preg_replace_callback('/\{(\w+)(?::\w+)?(\*)?\}/', function (array $m) use ($segments): string {
            $value = (string) ($segments[$m[1]] ?? '');
            return ($m[2] ?? '') === '*'
                ? implode('/', array_map('rawurlencode', explode('/', $value)))
                : rawurlencode($value);
        }, $route->pattern);
    }

    /**
     * @return Route[]
     */
    public function routes(): array
    {
        return $this->routes;
    }

    /** Route nommée, ou null si aucune route de ce nom n'est enregistrée. */
    public function routeByName(string $name): ?Route
    {
        return $this->named[$name] ?? null;
    }

    private function addRoute(string $method, string $pattern, array $handler, array $middleware, ?string $name, string|array|null $permission = null): self
    {
        $fullPattern = $this->groupPrefix . $pattern;
        // Un bundle désigne ses middlewares par les noms du contrat (kintai\Contract\Http\Middleware\…) : on garde le
        // nom réel de la classe, pour que toute comparaison (pipeline, contrôles de permissions, tests) reste valable.
        $fullMiddleware = array_map(
            static fn(mixed $m): mixed => is_string($m) ? Container::canonicalName($m) : $m,
            array_merge($this->groupMiddleware, $middleware),
        );

        // Compile pattern to regex — {name} capture un segment, {name*} capture le reste du chemin, {name:type}
        // capture un segment typé (store, employee…) que l'application traduit en identifiant (voir Routing\).
        $paramNames = [];
        $bindings   = [];
        $regex = preg_replace_callback('/\{(\w+)(?::(\w+))?(\*)?\}/', function ($m) use (&$paramNames, &$bindings) {
            $paramNames[] = $m[1];
            if (($m[2] ?? '') !== '') {
                $bindings[$m[1]] = $m[2];
            }
            return isset($m[3]) && $m[3] === '*' ? '(.+)' : '([^/]+)';
        }, $fullPattern);

        $regex = '#^' . $regex . '$#';

        $route = new Route(
            method: strtoupper($method),
            pattern: $fullPattern,
            regex: $regex,
            handler: $handler,
            paramNames: $paramNames,
            middleware: $fullMiddleware,
            name: $name,
            permission: $permission,
            bindings: $bindings,
        );

        $this->routes[] = $route;

        if ($name !== null) {
            $this->named[$name] = $route;
        }

        return $this;
    }
}
