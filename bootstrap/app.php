<?php

declare(strict_types=1);

use kintai\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use kintai\Http\ErrorResponder;
use kintai\Http\KintaiHttp;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        using: function (): void {
            // Routes de Kintai (routes/web.php, routes/api.php), puis celles de ses bundles : comme chez Kintai, la
            // première route déclarée qui correspond l'emporte. Ni groupe « web » ni groupe « api » de Laravel :
            // les middlewares de Kintai (config/middleware.php) sont dans la pile globale ci-dessous.
            $laravel = app();
            if (!KintaiHttp::shouldRegisterRoutes($laravel)) {
                return;
            }
            $kintai = KintaiHttp::app($laravel, deferBundles: true);
            require __DIR__ . '/../routes/web.php';
            require __DIR__ . '/../routes/api.php';
            $kintai->bootBundles();
        },
        commands: __DIR__ . '/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Aucun middleware global de Laravel (CORS, taille des POST, TrimStrings…) : Kintai a les siens. use([]) ne
        // suffit pas (une liste vide rend celle par défaut de Laravel) : on garde InvokeDeferredCallbacks, sans effet
        // sur la requête, qui exécute les defer() en fin de réponse.
        $middleware->use([
            \Illuminate\Foundation\Http\Middleware\InvokeDeferredCallbacks::class,
            \kintai\Http\Middleware\AccessLog::class,
            ...(require __DIR__ . '/../config/middleware.php')['global'],
        ]);
        $middleware->alias([
            'kintai.bind' => \kintai\Http\Middleware\BindRouteParameters::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Pages d'erreur et JSON de Kintai ; les erreurs HTTP prévues (404, 422, 429…) ne sont pas journalisées.
        $exceptions->dontReport([\kintai\Core\Exceptions\HttpException::class]);
        $exceptions->report(static function (Throwable $e): void {
            ErrorResponder::log($e);
        })->stop();
        $exceptions->render(ErrorResponder::render(...));
    })
    ->create();

// Même fichier d'environnement que Kintai (.env.local, puis .env.ovh, puis .env : voir kintai_load_env_file()).
foreach (['.env.local', '.env.ovh', '.env'] as $envFile) {
    if (is_file($app->basePath($envFile))) {
        $app->loadEnvironmentFrom($envFile);
        break;
    }
}

return $app;
