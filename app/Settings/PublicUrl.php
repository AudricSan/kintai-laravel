<?php

namespace App\Settings;

/**
 * URL publique de l'instance (schéma + domaine + éventuel sous-dossier), utilisée pour les liens absolus générés hors
 * d'une requête web : mails, notifications, flux iCal.
 */
final class PublicUrl
{
    /**
     * Valide et normalise une URL de base : http(s) uniquement, domaine obligatoire, ni identifiants, ni requête, ni
     * fragment ; sans « / » final, schéma et domaine en minuscules. Null pour toute autre valeur.
     */
    public static function normalize(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '' || strlen($url) > 255) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        if (preg_match('/^[a-z0-9.-]+$|^\[[0-9a-f:.]+\]$/i', $parts['host']) !== 1) {
            return null;
        }

        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';
        $path = rtrim($parts['path'] ?? '', '/');

        return $scheme.'://'.strtolower($parts['host']).$port.$path;
    }
}
