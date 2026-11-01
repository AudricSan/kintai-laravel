<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

/**
 * Masque les valeurs sensibles avant qu'une erreur soit écrite dans les journaux (storage/logs/error.log, journal
 * d'activité) : une exception ou une bibliothèque peut citer un mot de passe, un jeton, un en-tête Authorization ou
 * l'URL d'un lien secret (/ical/…, /reset-password/…). Le journal doit rester utile pour comprendre l'erreur sans
 * jamais permettre de rejouer un accès.
 */
final class LogScrubber
{
    private const MASK = '***';

    /** Le texte sans ses valeurs sensibles. */
    public static function scrub(string $text): string
    {
        $patterns = [
            // En-tête Authorization / jeton porteur.
            '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]{8,}/i'                                      => '$1 ' . self::MASK,
            // Identifiants dans une URL ou un DSN : scheme://utilisateur:motdepasse@hôte.
            '/\b([a-z][a-z0-9+.-]*:\/\/[^\s:\/@]+):[^\s@\/]+@/i'                                  => '$1:' . self::MASK . '@',
            // Liens secrets de l'application.
            '#/(ical|reset-password)/[^\s/?\'"]+#'                                                => '/$1/' . self::MASK,
            // champ=valeur ou "champ":"valeur" pour les noms qui portent un secret.
            '/\b(password|passwd|pwd|secret|token|api[_-]?key|authorization|remember[_-]?token|csrf)'
            . '(["\']?\s*[:=]>?\s*["\']?)[^\s"\'&,;)]+/i'                                         => '$1$2' . self::MASK,
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $text) ?? $text;
    }

    /** La pile d'appels d'une exception, sans les arguments (ils peuvent contenir mots de passe et jetons). */
    public static function trace(Throwable $e): string
    {
        $lines = [];
        foreach ($e->getTrace() as $i => $frame) {
            $call    = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '') . '()';
            $lines[] = sprintf('#%d %s%s: %s', $i, $frame['file'] ?? '[internal function]', isset($frame['line']) ? '(' . $frame['line'] . ')' : '', $call);
        }
        $lines[] = '#' . count($lines) . ' {main}';

        return implode("\n", $lines);
    }
}
