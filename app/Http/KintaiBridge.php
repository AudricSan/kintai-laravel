<?php

declare(strict_types=1);

namespace kintai\Http;

use kintai\Core\Application as KintaiApplication;
use kintai\Core\Request as KintaiRequest;
use kintai\Core\Response as KintaiResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pont entre le noyau HTTP de Laravel et l'application Kintai.
 *
 * Laravel reçoit la requête (public/index.php, routes/web.php) et la confie ici à l'application Kintai, qui la traite
 * comme avant : ses routes (routes/kintai.php et celles des bundles), ses middlewares, ses contrôleurs et ses pages
 * d'erreur. La réponse Kintai est ensuite convertie en réponse Symfony, que Laravel envoie.
 *
 * C'est l'étape de transition de la migration vers Laravel : les briques maison (routeur, conteneur, middlewares…)
 * seront remplacées une à une par celles de Laravel, sans changer le comportement de l'application.
 */
final class KintaiBridge
{
    public function __invoke(): SymfonyResponse
    {
        // Comme l'ancien public/index.php : toute erreur PHP signalée devient une exception, rendue en page 500.
        set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        $app = new KintaiApplication(BASE_PATH);
        $app->boot();

        return self::toSymfony($app->handle(new KintaiRequest()));
    }

    /** Convertit une réponse Kintai en réponse Symfony, sans rien changer à ce que reçoit le navigateur. */
    public static function toSymfony(KintaiResponse $response): SymfonyResponse
    {
        $path = $response->filePath();

        $symfony = $path !== null
            ? new StreamedResponse(static function () use ($path): void {
                readfile($path);
            }, $response->status())
            : new SymfonyResponse($response->body(), $response->status());
        // Même jeu de caractères par défaut que PHP (default_charset) : « text/html; charset=UTF-8 ».
        $symfony->setCharset('UTF-8');

        foreach ($response->headers() as $name => $value) {
            $symfony->headers->set($name, $value);
            // Kintai envoyait ses en-têtes avec header(), qui remplace un en-tête du même nom déjà posé par PHP
            // (Cache-Control de session_start(), par exemple). Symfony ne remplace pas : on retire l'ancien.
            if (!headers_sent()) {
                header_remove($name);
            }
        }

        // Symfony ajoute « Cache-Control: no-cache, private » à toute réponse qui n'en a pas. Kintai n'en envoyait
        // pas : seul reste celui que PHP a éventuellement posé (session_cache_limiter de session_start()).
        if (!array_key_exists('cache-control', array_change_key_case($response->headers()))) {
            $symfony->headers->remove('Cache-Control');
        }

        return $symfony;
    }
}
