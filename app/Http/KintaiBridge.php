<?php

declare(strict_types=1);

namespace kintai\Http;

use Illuminate\Foundation\Application;
use kintai\Core\Request as KintaiRequest;
use kintai\Core\Response as KintaiResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pont entre le noyau HTTP de Laravel et l'application Kintai.
 *
 * Les routes Kintai sont déclarées au routeur de Laravel (voir KintaiRoutes) ; une requête qu'aucune ne prend arrive
 * ici, et l'application Kintai la traite comme avant : son routeur répond 404 ou 405 avec ses propres pages d'erreur,
 * après ses middlewares globaux (session, langue…). La réponse Kintai est convertie en réponse Symfony, que Laravel
 * envoie.
 *
 * C'est l'étape de transition de la migration vers Laravel : les briques maison (routeur, conteneur, middlewares…)
 * seront remplacées une à une par celles de Laravel, sans changer le comportement de l'application.
 */
final class KintaiBridge
{
    public function __invoke(Application $laravel): SymfonyResponse
    {
        return self::toSymfony(KintaiHttp::app($laravel)->handle(new KintaiRequest()));
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
