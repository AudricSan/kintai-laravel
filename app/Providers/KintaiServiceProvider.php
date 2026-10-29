<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Console\Application as Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use App\Console\KintaiScriptCommand;
use App\BundleManager;
use App\Repositories\LanguageRepositoryInterface;
use App\Routing\EmployeeRouteBinder;
use App\Routing\RouteBinderRegistry;
use App\Routing\StoreRouteBinder;
use App\View\ViewRenderer;
use App\Foundation\Auth\KintaiGate;
use App\Foundation\Auth\KintaiGuard;

/**
 * Raccorde Laravel aux services de Kintai pendant la migration : les helpers de Laravel (__(), csrf_token(),
 * csrf_field(), session(), auth()) et le Gate rendent ce que rendaient les services de Kintai, et les scripts de
 * scripts/ deviennent des commandes Artisan.
 *
 */
final class KintaiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
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
            $views = new ViewRenderer(BASE_PATH . '/resources/views');
            $views->share('BASE_URL', base_url());
            return $views;
        });

        // Contrôleurs : la redirection ou la 404 d'un paramètre typé est rendue juste avant l'action.
        $this->app->bind(
            \Illuminate\Routing\Contracts\ControllerDispatcher::class,
            static fn($app): \App\Http\KintaiControllerDispatcher => new \App\Http\KintaiControllerDispatcher($app),
        );
    }

    public function boot(): void
    {
        \App\Http\Macros::register();

        if (config('session.driver') === 'file') {
            \Illuminate\Support\Facades\File::ensureDirectoryExists((string) config('session.files'));
        }


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
