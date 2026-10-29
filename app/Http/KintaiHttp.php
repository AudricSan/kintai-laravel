<?php

declare(strict_types=1);

namespace App\Http;

use Illuminate\Foundation\Application;

/**
 * Mise en route HTTP de Kintai, appelée au chargement des routes (bootstrap/app.php) : filet d'erreurs PHP, routes du
 * cœur (routes/web.php, routes/api.php) puis celles des bundles — comme chez Kintai, la première route déclarée qui
 * correspond l'emporte.
 */
final class KintaiHttp
{
    public static function register(Application $laravel): void
    {
        if (!self::shouldRegisterRoutes($laravel)) {
            return;
        }

        // Comme l'ancien public/index.php : toute erreur PHP signalée devient une exception, rendue en page 500.
        set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        // Erreur fatale non attrapée : au moins une trace dans storage/logs/error.log.
        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                ErrorResponder::log(new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']));
            }
        });

        require $laravel->basePath('routes/web.php');
        require $laravel->basePath('routes/api.php');

        // Puis les routes des bundles actifs.
        $laravel->make(\App\BundleManager::class)->loadQueuedRoutes();
    }

    /**
     * Les routes Kintai sont-elles déclarées à Laravel ? Pour toute requête HTTP, et pour `artisan route:list` ; les
     * autres commandes et les tests n'en ont pas besoin (et ne démarrent pas Kintai pour rien).
     */
    public static function shouldRegisterRoutes(Application $laravel): bool
    {
        if (!$laravel->runningInConsole()) {
            return true;
        }

        return $laravel->runningConsoleCommand('route:list') && is_file(storage_path('installed.lock'));
    }
}
