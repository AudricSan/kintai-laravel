<?php

declare(strict_types=1);

namespace kintai\Core\Services\Calendar;

use kintai\Core\Services\PublicUrlResolver;

/**
 * Adresse de retour OAuth, à déclarer à l'identique dans la console Google Cloud : URL publique de l'instance, sinon
 * celle de la requête courante. Affichée à l'Owner pour qu'il la recopie, et envoyée à Google pendant la liaison.
 */
final class GoogleRedirectUri
{
    public const PATH = '/profile/calendar/google/callback';

    public function __construct(private readonly PublicUrlResolver $publicUrl) {}

    /** @param string $basePath sous-dossier d'installation (« » à la racine du domaine) */
    public function resolve(string $basePath = ''): string
    {
        $root = $this->publicUrl->resolve();
        if ($root === null) {
            $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            $root  = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $basePath;
        }
        return rtrim($root, '/') . self::PATH;
    }
}
