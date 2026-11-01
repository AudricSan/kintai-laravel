<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cookie;

/**
 * Langue du dernier compte connecté sur ce navigateur, gardée après la déconnexion : la page de connexion s'affiche
 * dans cette langue (voir InstanceLocale pour l'ordre complet). Posé à la connexion et à chaque changement de langue
 * du profil ; retiré quand le compte suit la langue de l'instance. Cookie de Laravel (ajouté à la réponse par
 * AddQueuedCookiesToResponse).
 */
final class LocaleCookie
{
    public const NAME = 'kintai_locale';
    private const LIFETIME_MINUTES = 525600; // un an
    private const PATTERN = '/^[a-z]{2,5}(-[A-Za-z]{2,4})?$/';

    public static function read(): ?string
    {
        $value = request()->cookie(self::NAME);

        return is_string($value) && preg_match(self::PATTERN, $value) === 1 ? $value : null;
    }

    /** Langue du profil du compte connecté ('' ou null : suit l'instance, le cookie est retiré). */
    public static function remember(?string $language): void
    {
        $language = (string) $language;
        if ($language !== '' && preg_match(self::PATTERN, $language) === 1) {
            Cookie::queue(Cookie::make(self::NAME, $language, self::LIFETIME_MINUTES, '/', null, request()->isSecure(), true, false, 'lax'));
            request()->cookies->set(self::NAME, $language);

            return;
        }
        Cookie::queue(Cookie::forget(self::NAME));
        request()->cookies->remove(self::NAME);
    }
}
