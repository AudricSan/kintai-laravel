<?php

declare(strict_types=1);

use kintai\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        using: function (): void {
            require __DIR__ . '/../routes/web.php';
        },
        commands: __DIR__ . '/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Kintai applique ses propres middlewares globaux (config/middleware.php) : aucun middleware Laravel ne
        // retouche la requête avant elle.
        // Aucun middleware global de Laravel (CORS, taille des POST, TrimStrings…) : Kintai a les siens, dans sa pile.
        // use([]) ne suffit pas (une liste vide rend celle par défaut de Laravel) : on garde le seul sans effet sur
        // la requête, qui exécute les defer() en fin de réponse.
        $middleware->use([\Illuminate\Foundation\Http\Middleware\InvokeDeferredCallbacks::class]);
        // Middlewares Kintai dans la pile de Laravel (voir kintai\Http\KintaiRoutes::middleware()).
        $middleware->alias([
            'kintai.kernel'  => \kintai\Http\Middleware\KintaiKernel::class,
            'kintai.mw'      => \kintai\Http\Middleware\KintaiMiddleware::class,
            'kintai.resolve' => \kintai\Http\Middleware\ResolveKintaiRoute::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
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
