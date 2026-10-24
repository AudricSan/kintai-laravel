<?php

declare(strict_types=1);

namespace kintai\Core;

use kintai\Core\Exceptions\HttpException;
use kintai\Core\Exceptions\MethodNotAllowedException;
use kintai\Core\Exceptions\NotFoundException;
use kintai\Core\Exceptions\ValidationException;
use kintai\Core\Routing\EmployeeRouteBinder;
use kintai\Core\Routing\RouteBinderRegistry;
use kintai\Core\Routing\RouteParamBinder;
use kintai\Core\Routing\StoreRouteBinder;
use kintai\Core\Middleware\MiddlewarePipeline;
use kintai\Core\Repositories\LanguageRepositoryInterface;
use kintai\Core\Repositories\LogRepositoryInterface;
use kintai\Core\Repositories\TranslationRepositoryInterface;
use kintai\UI\ViewRenderer;
use kintai\Core\Services\AppSettingsService;
use kintai\Core\Services\Log;
use kintai\Core\Services\TranslationService;
use Throwable;

final class Application
{
    private readonly Container $container;
    private readonly Router $router;
    private readonly MiddlewarePipeline $pipeline;
    private readonly BundleManager $bundleManager;
    private readonly string $basePath;

    /** @var string[] Global middleware applied to every request */
    private array $globalMiddleware = [];

    private bool $debug = false;

    /** @var string[] List of registered service providers */
    private array $providers = [
        \kintai\Core\Database\DatabaseServiceProvider::class,
        \kintai\Core\Repositories\RepositoryServiceProvider::class,
        \kintai\Core\Auth\AuthServiceProvider::class,
        \kintai\Core\Services\AppServiceProvider::class,
        \kintai\Core\LicenseServiceProvider::class,
        \kintai\Core\BundleServiceProvider::class,
    ];

    /** @var ServiceProvider[] Instances of booted providers */
    private array $providerInstances = [];

    public function __construct(string $basePath)
    {
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', rtrim($basePath, '/\\'));
        }
        $this->basePath = BASE_PATH;
        $this->container = Container::getInstance();
        $this->router = new Router();
        $this->pipeline = new MiddlewarePipeline($this->container);
        $this->bundleManager = new BundleManager($this);

        // Register core instances
        $this->container->instance(self::class, $this);
        $this->container->instance(Container::class, $this->container);
        $this->container->instance(Router::class, $this->router);
        $this->container->instance(MiddlewarePipeline::class, $this->pipeline);
        $this->container->instance(BundleManager::class, $this->bundleManager);

        // Paramètres de route typés ({id:store}, {uid:employee}) : segments lisibles ↔ identifiants (voir Routing\)
        $this->container->singleton(RouteBinderRegistry::class, function (): RouteBinderRegistry {
            $registry = new RouteBinderRegistry();
            $registry->register('store', fn() => $this->container->make(StoreRouteBinder::class));
            $registry->register('employee', fn() => $this->container->make(EmployeeRouteBinder::class));
            return $registry;
        });
        $this->router->setBinderResolver(function (string $type): ?RouteParamBinder {
            $registry = $this->container->make(RouteBinderRegistry::class);
            return $registry->has($type) ? $registry->get($type) : null;
        });

        // Register ViewRenderer
        $this->container->singleton(ViewRenderer::class, fn() => new ViewRenderer(
            $this->basePath . '/app/UI/View'
        ));

        // Register TranslationService
        $this->container->singleton(TranslationService::class, fn() => new TranslationService(
            $this->container->make(TranslationRepositoryInterface::class),
            $this->container->make(LanguageRepositoryInterface::class),
        ));

        $this->registerProviders();
    }

    public function basePath(string $path = ''): string
    {
        return $this->basePath . ($path ? '/' . ltrim($path, '/') : '');
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function setGlobalMiddleware(array $middleware): void
    {
        $this->globalMiddleware = $middleware;
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }

    /**
     * Load configuration, routes, then boot providers.
     */
    public function boot(): void
    {
        $this->loadConfig();
        $this->loadRoutes();
        $this->bootProviders();
        $this->bundleManager->boot();

        // Handler PHP : convertir les erreurs PHP en exceptions (uniquement en debug)
        if ($this->isDebug()) {
            set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
                if (!(error_reporting() & $severity)) {
                    return false;
                }
                throw new \ErrorException($message, 0, $severity, $file, $line);
            });
        }

        // Handler shutdown : capture les fatales non catchées
        register_shutdown_function(function (): void {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $this->logError(new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']));
            }
        });
    }

    public function handleRequest(): void
    {
        $this->handle(new Request())->send();
    }

    /**
     * Traite une requête jusqu'à sa réponse, sans l'envoyer : routage, middlewares, contrôleur, rendu des erreurs et
     * journal d'accès. Appelé depuis les routes Laravel (kintai\Http\KintaiRouteAction, kintai\Http\KintaiBridge), qui
     * convertissent ensuite la réponse.
     *
     * @param array{0: Route, 1: array<string, string>}|null $match route déjà trouvée par le routeur de Laravel, avec
     *        ses paramètres ; null : le routeur Kintai la cherche (et lève 404 ou 405 s'il n'y en a pas).
     */
    public function handle(Request $request, ?array $match = null): Response
    {
        $this->container->instance(Request::class, $request);
        $startedAt = microtime(true);

        try {
            $response = $this->dispatch($request, $match);
        } catch (Throwable $e) {
            $response = $this->handleException($e, $request);
        }

        $this->logAccess($request, $response, $startedAt);

        return $response;
    }

    /**
     * Chemins exclus du journal d'accès automatique : polling/streaming haute
     * fréquence (notifications, SSE) qui saturerait activity_log sans valeur
     * d'audit ajoutée, et le health-check API destiné aux moniteurs externes.
     */
    private const ACCESS_LOG_EXCLUDED_URIS = [
        '/notifications/poll',
        '/api/v1/ping',
    ];

    private function logAccess(Request $request, Response $response, float $startedAt): void
    {
        $uri = $request->uri();
        if (str_ends_with($uri, '/stream') || in_array($uri, self::ACCESS_LOG_EXCLUDED_URIS, true)) {
            return;
        }

        try {
            $settings = $this->container->make(AppSettingsService::class);
            if (!$settings->accessLogEnabled()) {
                return;
            }

            $status = $response->status();
            $level = match (true) {
                $status >= 500 => LogRepositoryInterface::LEVEL_ERROR,
                $status >= 400 => LogRepositoryInterface::LEVEL_WARNING,
                default        => LogRepositoryInterface::LEVEL_INFO,
            };
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            Log::write(
                $level,
                LogRepositoryInterface::CHANNEL_ACCESS,
                sprintf('%s %s', $request->method(), $uri),
                [],
                null,
                null,
                $status,
                $durationMs,
            );
        } catch (Throwable) {
            // Le logging ne doit jamais faire planter l'application
        }
    }

    /**
     * Le routage se fait DANS le "core" du pipeline global (et non avant), pour que les
     * middlewares globaux (session, i18n...) s'exécutent même quand aucune route ne
     * correspond — sinon Router::dispatch() lève NotFoundException/MethodNotAllowedException
     * avant que I18nMiddleware n'ait jamais tourné, et la page 404/405 affiche les clés de
     * traduction brutes (__() retombe sur la clé faute de locale définie). Voir CHANGELOG.
     */
    /** @param array{0: Route, 1: array<string, string>}|null $match */
    private function dispatch(Request $request, ?array $match = null): Response
    {
        $resolveAndRun = function (Request $request) use ($match): Response {
            [$route, $params] = $match ?? $this->router->dispatch($request->method(), $request->uri());

            // Paramètres typés traduits en identifiants AVANT les middlewares de route (les contrôles d'accès les
            // lisent), mais la 301 ou la 404 qui en découle n'est rendue qu'APRÈS eux : un visiteur non connecté ou
            // sans droit ne doit apprendre ni le nom d'un magasin ni l'existence d'un numéro d'employé.
            $deferred = null;
            if ($route->bindings !== []) {
                [$params, $deferred] = $this->bindRouteParams($route, $params, $request);
            }

            $request->setRouteParams($params);
            $request->setAttribute('route_name', $route->name);
            // Règle RBAC déclarée sur la route elle-même (voir Route::$permission),
            // exploitée par PermissionMiddleware/ApiPermissionMiddleware.
            $request->setAttribute('route_permission', $route->permission);

            // Core handler: resolve controller, call method
            $core = function (Request $request) use ($route, $deferred) {
                if ($deferred instanceof Throwable) {
                    throw $deferred;
                }
                if ($deferred instanceof Response) {
                    return $deferred;
                }
                [$controllerClass, $method] = $route->handler;
                $controller = $this->container->make($controllerClass);
                return $controller->$method($request);
            };

            return $this->pipeline->run($request, $route->middleware, $core);
        };

        return $this->pipeline->run($request, $this->globalMiddleware, $resolveAndRun);
    }

    /**
     * Traduit les paramètres typés ({id:store}, {uid:employee}) en identifiants numériques, AVANT les middlewares
     * de route : contrôleurs et contrôles d'accès (PermissionMiddleware) continuent de lire un identifiant via
     * $request->param(). Un ancien lien (identifiant nu, alias historique) est redirigé en 301 vers l'URL
     * canonique en GET/HEAD ; les autres méthodes (formulaires POST) sont servies sans redirection.
     *
     * Renvoie les paramètres résolus et, le cas échéant, ce qu'il faudra rendre à la place du contrôleur une fois
     * les middlewares passés : la redirection 301, ou la 404 d'un segment inconnu (son paramètre vaut alors « 0 »).
     *
     * @param array<string, string> $params
     * @return array{0: array<string, string>, 1: Response|NotFoundException|null}
     */
    private function bindRouteParams(Route $route, array $params, Request $request): array
    {
        $segments = $params;
        $redirect = false;

        foreach ($route->bindings as $name => $type) {
            $binder = $this->router->binderFor($type);
            if ($binder === null || !isset($params[$name])) {
                continue;
            }
            $bound = $binder->resolve($params[$name]);
            if ($bound === null) {
                $missing       = new NotFoundException("No [{$type}] matches [{$params[$name]}].");
                $params[$name] = '0';
                return [$params, $missing];
            }
            $redirect        = $redirect || !$bound->canonical;
            $segments[$name] = $binder->segmentFor($bound->id);
            $params[$name]   = (string) $bound->id;
        }

        if ($redirect && in_array($request->method(), ['GET', 'HEAD'], true)) {
            $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
            return [$params, Response::redirect(
                base_url() . $this->router->pathFor($route, $segments) . ($query !== '' ? '?' . $query : ''),
                301,
            )];
        }

        return [$params, null];
    }

    private function handleException(Throwable $e, Request $request): Response
    {
        $wantsJson = $request->wantsJson() || $request->getAttribute('wantsJson', false);

        if ($e instanceof ValidationException) {
            if ($wantsJson) {
                return Response::json(['error' => $e->getMessage(), 'errors' => $e->errors], 422);
            }
            // Formulaire web : retour au formulaire avec les messages précis et la saisie conservée.
            return ValidationFeedback::redirectBack($e, $request)
                ?? $this->renderError(422, implode(' ', ValidationFeedback::messages($e)), $request);
        }

        if ($e instanceof MethodNotAllowedException) {
            $response = $wantsJson
                ? Response::json(['error' => $e->getMessage()], 405)
                : $this->renderError(405, $e->getMessage(), $request);
            return $response->withHeader('Allow', implode(', ', $e->allowedMethods));
        }

        if ($e instanceof HttpException) {
            $response = $wantsJson
                ? Response::json(['error' => $e->getMessage()], $e->statusCode)
                : $this->renderError($e->statusCode, $e->getMessage(), $request);
            // En-têtes portés par l'exception (ex. Retry-After d'un 429).
            foreach ($e->headers as $name => $value) {
                $response = $response->withHeader((string) $name, (string) $value);
            }
            return $response;
        }

        // Unexpected error
        $this->logError($e);

        $message = __('error_500_message');

        return $wantsJson
            ? Response::json(['error' => $message], 500)
            : $this->renderError(500, $message, $request);
    }

    private function renderError(int $status, string $message, Request $request): Response
    {
        try {
            $view = $this->container->make(ViewRenderer::class);
            $html = $view->render("errors.{$status}", [
                'status' => $status,
                'message' => $message,
            ]);
            return Response::html($html, $status);
        } catch (Throwable) {
            // Fallback if view doesn't exist
            return Response::html(
                "<h1>{$status}</h1><p>" . htmlspecialchars($message) . "</p>",
                $status,
            );
        }
    }

    private function logError(Throwable $e): void
    {
        $logDir = $this->basePath . '/storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }

        $entry = sprintf(
            "[%s] %s: %s in %s:%d\n%s\n\n",
            date('Y-m-d H:i:s'),
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString(),
        );

        @file_put_contents($logDir . '/error.log', $entry, FILE_APPEND | LOCK_EX);

        // Également logger en base via le système unifié
        try {
            Log::error($e->getMessage(), [
                'exception' => get_class($e),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
                'trace'     => $e->getTraceAsString(),
            ]);
        } catch (\Throwable) {
            // Le logging ne doit jamais faire planter l'application
        }
    }

    private function loadConfig(): void
    {
        $this->container->make(ViewRenderer::class)->share('BASE_URL', base_url());

        $configFile = $this->basePath . '/config/app.php';
        if (file_exists($configFile)) {
            $config = require $configFile;
            if (isset($config['debug']) && $config['debug']) {
                $this->debug = true;
                error_reporting(E_ALL);
                ini_set('display_errors', '1');
            } else {
                error_reporting(0);
                ini_set('display_errors', '0');
            }
            if (isset($config['timezone'])) {
                date_default_timezone_set($config['timezone']);
            }
        }

        // Load middleware config
        $middlewareFile = $this->basePath . '/config/middleware.php';
        if (file_exists($middlewareFile)) {
            $middleware = require $middlewareFile;
            $this->globalMiddleware = $middleware['global'] ?? [];
        }
    }

    private function loadRoutes(): void
    {
        $routesFile = $this->basePath . '/routes/kintai.php';
        if (file_exists($routesFile)) {
            $router = $this->router;
            $container = $this->container;
            require $routesFile;
        }
    }

    private function registerProviders(): void
    {
        foreach ($this->providers as $providerClass) {
            $provider = new $providerClass($this->container);
            $provider->register();
            $this->providerInstances[] = $provider;
        }
    }

    private function bootProviders(): void
    {
        foreach ($this->providerInstances as $provider) {
            $provider->boot();
        }
    }
}
