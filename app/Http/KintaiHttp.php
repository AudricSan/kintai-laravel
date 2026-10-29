<?php

declare(strict_types=1);

namespace kintai\Http;

use Illuminate\Foundation\Application;
use kintai\Core\Application as KintaiApplication;
use kintai\Core\Container as KintaiContainer;

/**
 * Démarrage des services de Kintai pour une requête HTTP (ou une tâche) servie par Laravel.
 *
 * Une seule application Kintai par processus : démarrée au chargement des routes (bootstrap/app.php), pour que les
 * routes des bundles soient déclarées au routeur de Laravel, puis reprise par qui en a besoin.
 */
final class KintaiHttp
{
    /**
     * @param bool $deferBundles true : les bundles ne sont pas démarrés (leurs routes viennent après celles du cœur,
     *        voir KintaiApplication::bootBundles()). Sans effet si l'application est déjà démarrée.
     */
    public static function app(Application $laravel, bool $deferBundles = false): KintaiApplication
    {
        if ($laravel->bound(KintaiApplication::class)) {
            return $laravel->make(KintaiApplication::class);
        }

        // Comme l'ancien public/index.php : toute erreur PHP signalée devient une exception, rendue en page 500.
        set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        // Les services Kintai s'enregistrent dans le conteneur de l'application Laravel : un seul conteneur. Kintai
        // s'y enregistre lui-même (KintaiApplication::class), ce qui le rend disponible au reste de la requête.
        KintaiContainer::setInstance(new KintaiContainer($laravel));

        $app = new KintaiApplication(BASE_PATH);
        $app->boot(bundles: !$deferBundles);

        return $app;
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
