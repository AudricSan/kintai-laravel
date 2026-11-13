<?php

use App\Http\Api\ApiErrorRenderer;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\MaintenanceMode;
use App\Http\Middleware\RedirectToInstaller;
use App\Http\Middleware\RunSystemAlertsOnVisit;
use App\Http\Middleware\SecurityHeaders;
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
        // Flux iCal public et appel des tâches planifiées : hors du groupe `web`, donc sans session ni cookie.
        then: function (): void {
            Route::middleware([])->group(base_path('routes/ical.php'));
            // Appel HTTP des tâches planifiées : idem, sans session ni cookie.
            Route::middleware([])->group(base_path('routes/cron.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Le « one-click » des clients de messagerie poste sans jeton CSRF : la signature du lien fait foi.
        $middleware->validateCsrfTokens(except: ['notifications/unsubscribe']);

        // Hôtes de confiance : seul l'hôte d'APP_URL, dès que celui-ci est réglé sur un vrai domaine (pas localhost).
        $middleware->trustHosts(at: function (): array {
            $host = parse_url((string) config('app.url'), PHP_URL_HOST);

            return is_string($host) && ! in_array($host, ['localhost', '127.0.0.1', '::1'], true) && ! str_ends_with($host, '.test')
                ? ['^'.preg_quote($host, '/').'$']
                : [];
        }, subdomains: false);

        // En-têtes de sécurité sur toutes les réponses (web, API, iCal, santé).
        $middleware->append(SecurityHeaders::class);

        // Tant que Kintai n'est pas installé : tout renvoie vers /install, sans base (sessions et cache en fichier).
        $middleware->web(prepend: [RedirectToInstaller::class]);

        $middleware->web(append: [
            AuthenticateSession::class,
            EnsureAccountIsActive::class,
            SetUserLocale::class,
            // Après la session : l'Owner connecté garde l'accès pendant la maintenance. L'API a le sien (routes/api.php).
            MaintenanceMode::class,
            // Filet sans planificateur : alertes système vérifiées quand un Owner charge une page (après la réponse).
            RunSystemAlertsOnVisit::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['db_password']);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Toute erreur de `/api/*` : JSON `{error, code}` (voir ApiErrorRenderer).
        $exceptions->render(fn (Throwable $e, Request $request): ?Response => ApiErrorRenderer::handles($request)
            ? ApiErrorRenderer::render($e, $request)
            : null);
    })->create();
