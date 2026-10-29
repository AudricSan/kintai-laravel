<?php

declare(strict_types=1);

namespace kintai\Providers;

use Illuminate\Console\Application as Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use kintai\Console\KintaiScriptCommand;
use kintai\Core\BundleManager;
use kintai\Core\Repositories\LanguageRepositoryInterface;
use kintai\Core\Repositories\TranslationRepositoryInterface;
use kintai\Core\Routing\EmployeeRouteBinder;
use kintai\Core\Routing\RouteBinderRegistry;
use kintai\Core\Routing\StoreRouteBinder;
use kintai\Core\Services\TranslationService;
use kintai\UI\ViewRenderer;
use kintai\Foundation\Auth\KintaiGate;
use kintai\Foundation\Auth\KintaiGuard;
use kintai\Foundation\Bridge\KintaiTranslator;

/**
 * Raccorde Laravel aux services de Kintai pendant la migration : les helpers de Laravel (__(), csrf_token(),
 * csrf_field(), session(), auth()) et le Gate rendent ce que rendaient les services de Kintai, et les scripts de
 * scripts/ deviennent des commandes Artisan.
 *
 * Enregistrés comme instances : un fournisseur différé de Laravel (traductions) ne les remplace pas ensuite.
 */
final class KintaiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->instance('translator', new KintaiTranslator());

        defined('BASE_PATH') || define('BASE_PATH', rtrim($this->app->basePath(), '/\\'));

        // Paramètres de route typés ({id:store}, {uid:employee}) : segments lisibles ↔ identifiants (voir Core\Routing)
        $this->app->singleton(RouteBinderRegistry::class, function ($app): RouteBinderRegistry {
            $registry = new RouteBinderRegistry();
            $registry->register('store', fn() => $app->make(StoreRouteBinder::class));
            $registry->register('employee', fn() => $app->make(EmployeeRouteBinder::class));
            return $registry;
        });

        $this->app->singleton(BundleManager::class);

        // Le moteur de vues de l'application est celui de Kintai (Blade, directive @includeInScope, vues .php) : les
        // vues des bundles (loadViewsFrom) et view() passent par lui.
        $this->app->extend('view', fn($view, $app) => $app->make(ViewRenderer::class)->factory());

        $this->app->singleton(ViewRenderer::class, function (): ViewRenderer {
            $views = new ViewRenderer(BASE_PATH . '/app/UI/View');
            $views->share('BASE_URL', base_url());
            return $views;
        });

        $this->app->singleton(TranslationService::class, fn($app) => new TranslationService(
            $app->make(TranslationRepositoryInterface::class),
            $app->make(LanguageRepositoryInterface::class),
        ));

        // Contrôleurs : la redirection ou la 404 d'un paramètre typé est rendue juste avant l'action.
        $this->app->bind(
            \Illuminate\Routing\Contracts\ControllerDispatcher::class,
            static fn($app): \kintai\Http\KintaiControllerDispatcher => new \kintai\Http\KintaiControllerDispatcher($app),
        );
    }

    public function boot(): void
    {
        \kintai\Http\Macros::register();


        // auth() et le Gate de Laravel lisent l'utilisateur et les permissions de Kintai.
        Auth::extend('kintai', static fn(): KintaiGuard => new KintaiGuard());
        Gate::before(KintaiGate::before(...));

        // Les scripts de scripts/ sont aussi des commandes Artisan (php artisan kintai:cron backup…).
        Artisan::starting(static function (Artisan $artisan): void {
            foreach (KintaiScriptCommand::SCRIPTS as $name => [$script, $description]) {
                $artisan->add(new KintaiScriptCommand($name, $script, $description));
            }
        });
    }
}
