<?php

use App\Http\Api\ApiErrorRenderer;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\SetUserLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
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

        // Toute erreur de `/api/*` : JSON `{error, code}` (voir ApiErrorRenderer).
        $exceptions->render(fn (Throwable $e, Request $request): ?Response => ApiErrorRenderer::handles($request)
            ? ApiErrorRenderer::render($e, $request)
            : null);
    })->create();
