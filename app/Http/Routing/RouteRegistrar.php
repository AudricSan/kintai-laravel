<?php

declare(strict_types=1);

namespace kintai\Http\Routing;

use Illuminate\Routing\Router;
use kintai\Core\Container;

/**
 * Enregistrement de routes à la manière du contrat de bundle ($router->get('/chemin/{id:store}', [Contrôleur::class, 'm'],
 * middleware: [...], name: '...', permission: '...')), au-dessus du routeur de Laravel. Transitoire : les bundles
 * passeront à Route:: avec leur service provider.
 *
 * Une route au même chemin (ou qui ne diffère que par le nom d'un paramètre) qu'une route déjà déclarée est ignorée :
 * la première l'emporte, comme chez Kintai, alors que Laravel garderait la dernière.
 */
final class RouteRegistrar
{
    private string $groupPrefix = '';

    /** @var list<string> */
    private array $groupMiddleware = [];

    public function __construct(private readonly Router $router) {}

    public function get(string $pattern, array $handler, array $middleware = [], ?string $name = null, string|array|null $permission = null): self
    {
        return $this->add('GET', $pattern, $handler, $middleware, $name, $permission);
    }

    public function post(string $pattern, array $handler, array $middleware = [], ?string $name = null, string|array|null $permission = null): self
    {
        return $this->add('POST', $pattern, $handler, $middleware, $name, $permission);
    }

    public function put(string $pattern, array $handler, array $middleware = [], ?string $name = null, string|array|null $permission = null): self
    {
        return $this->add('PUT', $pattern, $handler, $middleware, $name, $permission);
    }

    public function patch(string $pattern, array $handler, array $middleware = [], ?string $name = null, string|array|null $permission = null): self
    {
        return $this->add('PATCH', $pattern, $handler, $middleware, $name, $permission);
    }

    public function delete(string $pattern, array $handler, array $middleware = [], ?string $name = null, string|array|null $permission = null): self
    {
        return $this->add('DELETE', $pattern, $handler, $middleware, $name, $permission);
    }

    /**
     * @param callable(self): void $callback
     * @param string[] $middleware appliqués à toutes les routes du groupe
     */
    public function group(string $prefix, callable $callback, array $middleware = []): self
    {
        $previousPrefix     = $this->groupPrefix;
        $previousMiddleware = $this->groupMiddleware;

        $this->groupPrefix     = $previousPrefix . $prefix;
        $this->groupMiddleware = array_merge($previousMiddleware, $middleware);

        $callback($this);

        $this->groupPrefix     = $previousPrefix;
        $this->groupMiddleware = $previousMiddleware;

        return $this;
    }

    private function add(string $verb, string $pattern, array $handler, array $middleware, ?string $name, string|array|null $permission): self
    {
        $full = $this->groupPrefix . $pattern;

        $bindings  = [];
        $wildcards = [];
        $uri = preg_replace_callback('/\{(\w+)(?::(\w+))?(\*)?\}/', static function (array $m) use (&$bindings, &$wildcards): string {
            if (($m[2] ?? '') !== '') {
                $bindings[$m[1]] = $m[2];
            }
            if (($m[3] ?? '') === '*') {
                $wildcards[] = $m[1];
            }

            return '{' . $m[1] . '}';
        }, $full);

        $shape = $verb . ' /' . trim(preg_replace('/\{\w+\}/', '{}', $uri), '/');
        foreach ($this->router->getRoutes()->getRoutes() as $existing) {
            if (in_array($verb, $existing->methods(), true)
                && $shape === $verb . ' /' . trim(preg_replace('/\{\w+\}/', '{}', '/' . $existing->uri()), '/')) {
                return $this;
            }
        }

        // Un bundle désigne ses middlewares par les noms du contrat (kintai\Contract\Http\Middleware\…) : on garde le
        // nom réel de la classe.
        $stack = array_map(
            static fn(mixed $m): mixed => is_string($m) ? Container::canonicalName($m) : $m,
            array_merge($this->groupMiddleware, $middleware),
        );

        $route = $this->router->addRoute([$verb], $uri, $handler);
        foreach ($wildcards as $param) {
            $route->where($param, '.+');
        }
        if ($bindings !== []) {
            $route->bindSegments($bindings);
        }
        if ($stack !== []) {
            $route->middleware($stack);
        }
        if ($name !== null) {
            $route->name($name);
        }
        if ($permission !== null) {
            $route->permission($permission);
        }

        return $this;
    }
}
