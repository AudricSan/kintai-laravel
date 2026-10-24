<?php

declare(strict_types=1);

namespace kintai\Core;

final readonly class Route
{
    /**
     * @param string $method HTTP method
     * @param string $pattern Original pattern (e.g. /stores/{storeId}/shifts)
     * @param string $regex Compiled regex
     * @param array $handler [ControllerClass, method]
     * @param string[] $paramNames Ordered parameter names
     * @param string[] $middleware Middleware class names
     * @param string|null $name Route name for URL generation
     * @param string|array|null $permission Règle RBAC consommée par PermissionMiddleware/
     *        ApiPermissionMiddleware : une clé de PermissionCatalog ('categorie.action'), un
     *        tableau de règle ('perm' + 'self'/'membership'/'store_param'), ou la chaîne
     *        littérale 'public' pour une route volontairement exemptée de RBAC (libre-service,
     *        agrégat, ou déjà protégée par un requireOwner()/OwnerOnlyMiddleware dédié). null
     *        pour une route hors du périmètre RBAC (pas sous /admin ni /api/v1, ex. /login).
     */
    public function __construct(
        public string $method,
        public string $pattern,
        public string $regex,
        public array $handler,
        public array $paramNames,
        public array $middleware = [],
        public ?string $name = null,
        public string|array|null $permission = null,
        /** @var array<string, string> paramètre typé → type ({id:store} donne ['id' => 'store']) */
        public array $bindings = [],
    ) {}
}
