<?php

declare(strict_types=1);

namespace kintai\Core;

use kintai\Core\Routing\EmployeeRouteBinder;
use kintai\Core\Routing\RouteBinderRegistry;
use kintai\Core\Routing\StoreRouteBinder;
use kintai\Core\Repositories\LanguageRepositoryInterface;
use kintai\Core\Repositories\TranslationRepositoryInterface;
use kintai\Http\ErrorResponder;
use kintai\Http\Routing\RouteRegistrar;
use kintai\UI\ViewRenderer;
use kintai\Core\Services\TranslationService;

/**
 * Démarrage des services de Kintai (fournisseurs, bundles, configuration) au-dessus de l'application Laravel. Les
 * requêtes HTTP, elles, passent par le noyau de Laravel (routes/web.php, routes/api.php, bootstrap/app.php).
 */
final class Application
{
    private readonly Container $container;
    private readonly BundleManager $bundleManager;
    private readonly string $basePath;

    private ?RouteRegistrar $routeRegistrar = null;

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
        $this->bundleManager = new BundleManager($this);

        // Register core instances
        $this->container->instance(self::class, $this);
        $this->container->instance(Container::class, $this->container);
        $this->container->instance(BundleManager::class, $this->bundleManager);

        // Paramètres de route typés ({id:store}, {uid:employee}) : segments lisibles ↔ identifiants (voir Routing\)
        $this->container->singleton(RouteBinderRegistry::class, function (): RouteBinderRegistry {
            $registry = new RouteBinderRegistry();
            $registry->register('store', fn() => $this->container->make(StoreRouteBinder::class));
            $registry->register('employee', fn() => $this->container->make(EmployeeRouteBinder::class));
            return $registry;
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

    /** Déclaration des routes des bundles ($router de leurs fichiers de routes), au-dessus du routeur de Laravel. */
    public function router(): RouteRegistrar
    {
        return $this->routeRegistrar ??= new RouteRegistrar($this->container->laravel()->make('router'));
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }

    /**
     * Charge la configuration puis démarre les fournisseurs et les bundles.
     *
     * @param bool $bundles false : les bundles sont démarrés plus tard par bootBundles() — le service HTTP déclare
     *        d'abord les routes du cœur, pour qu'elles l'emportent sur celles d'un bundle au même chemin.
     */
    public function boot(bool $bundles = true): void
    {
        $this->loadConfig();
        $this->bootProviders();
        if ($bundles) {
            $this->bootBundles();
        }

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
                ErrorResponder::log(new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']));
            }
        });
    }

    public function bootBundles(): void
    {
        $this->bundleManager->boot();
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
