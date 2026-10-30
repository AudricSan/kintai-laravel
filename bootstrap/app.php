<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\ErrorResponder;
use App\Http\KintaiHttp;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        using: function (): void {
            // Routes de Kintai (routes/web.php, routes/api.php), puis celles de ses bundles : comme chez Kintai, la
            // première route déclarée qui correspond l'emporte. Ni groupe « web » ni groupe « api » de Laravel :
            // les middlewares sont dans la pile globale ci-dessous.
            KintaiHttp::register(app());
        },
        commands: __DIR__ . '/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Aucun middleware global de Laravel (CORS, taille des POST, TrimStrings…) : Kintai a les siens. use([]) ne
        // suffit pas (une liste vide rend celle par défaut de Laravel) : on garde InvokeDeferredCallbacks, sans effet
        // sur la requête, qui exécute les defer() en fin de réponse.
        $middleware->use([
            \Illuminate\Foundation\Http\Middleware\InvokeDeferredCallbacks::class,
            \App\Http\Middleware\AccessLog::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            // $errors dans les vues : erreurs de validation flashées par withErrors().
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            // Session fermée quand le mot de passe a changé depuis son ouverture (y compris sur les autres appareils).
            \Illuminate\Session\Middleware\AuthenticateSession::class,
            \App\Http\Middleware\SecurityHeadersMiddleware::class,
            // Limitation de débit de /api/* (toutes routes, bundles compris), avant tout le reste du traitement.
            \App\Http\Middleware\ApiRateLimitMiddleware::class,
            // Langue avant le contrôle CSRF : son refus (session expirée) s'affiche traduit.
            \App\Http\Middleware\I18nMiddleware::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \App\Http\Middleware\AppSettingsMiddleware::class,
            \App\Http\Middleware\MaintenanceModeMiddleware::class,
            \App\Http\Middleware\PasswordReminderMiddleware::class,
            // Variables de vue des bundles (Bundle::viewVariables()) et managed_store_ids pour la navigation.
            \App\Http\Middleware\BundleViewDataMiddleware::class,
            \App\Http\Middleware\NotificationMiddleware::class,
        ]);
        // Session révoquée : retour à la page de connexion de Kintai.
        $middleware->redirectGuestsTo(static fn() => base_url() . '/login');
        // Hors jeton CSRF : l'API (jetons d'API) et le désabonnement en un clic d'un client e-mail (RFC 8058), sans
        // session, qui se protège par une signature dans l'adresse.
        $middleware->validateCsrfTokens(except: ['api/*', 'notifications/unsubscribe']);
        $middleware->alias([
            'kintai.bind' => \App\Http\Middleware\BindRouteParameters::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Pages d'erreur et JSON de Kintai ; les erreurs HTTP prévues (404, 422, 429…) ne sont pas journalisées.
        $exceptions->dontReport([\App\Exceptions\HttpException::class]);
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
