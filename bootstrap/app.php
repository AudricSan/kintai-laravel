<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\SetUserLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Flux iCal public : hors du groupe `web`, donc sans session ni cookie.
        then: function (): void {
            Route::middleware([])->group(base_path('routes/ical.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Le « one-click » des clients de messagerie poste sans jeton CSRF : la signature du lien fait foi.
        $middleware->validateCsrfTokens(except: ['notifications/unsubscribe']);

        $middleware->web(append: [
            AuthenticateSession::class,
            EnsureAccountIsActive::class,
            SetUserLocale::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
