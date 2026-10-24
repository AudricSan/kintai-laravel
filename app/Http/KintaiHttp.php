<?php

declare(strict_types=1);

namespace kintai\Http;

use Illuminate\Foundation\Application;
use kintai\Core\Application as KintaiApplication;
use kintai\Core\Container as KintaiContainer;

/**
 * Démarrage de l'application Kintai pour une requête HTTP servie par Laravel.
 *
 * Une seule application Kintai par requête : démarrée au chargement des routes (routes/web.php), pour que ses routes
 * et celles des bundles soient déclarées au routeur de Laravel, puis reprise par l'action de la route trouvée.
 */
final class KintaiHttp
{
    public static function app(Application $laravel): KintaiApplication
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
        $app->boot();

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
