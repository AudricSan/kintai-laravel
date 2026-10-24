<?php

declare(strict_types=1);

namespace kintai\Core\Services;

/**
 * Langue du dernier compte connecté sur ce navigateur, gardée après la déconnexion : la page de connexion s'affiche
 * dans cette langue (voir InstanceLocale pour l'ordre complet). Posé à la connexion et à chaque changement de langue
 * du profil ; retiré quand le compte suit la langue de l'instance.
 */
final class LocaleCookie
{
    public const NAME = 'kintai_locale';
    private const LIFETIME = 31536000; // un an

    public static function read(): ?string
    {
        $value = $_COOKIE[self::NAME] ?? null;
        return is_string($value) && preg_match('/^[a-z]{2,5}(-[A-Za-z]{2,4})?$/', $value) === 1 ? $value : null;
    }

    /** Langue du profil du compte connecté ('' ou null : suit l'instance, le cookie est retiré). */
    public static function remember(?string $language): void
    {
        $language = (string) $language;
        $keep = $language !== '' && preg_match('/^[a-z]{2,5}(-[A-Za-z]{2,4})?$/', $language) === 1;
        if (!headers_sent()) {
            setcookie(self::NAME, $keep ? $language : '', [
                'expires'  => $keep ? time() + self::LIFETIME : time() - 3600,
                'path'     => '/',
                'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        if ($keep) {
            $_COOKIE[self::NAME] = $language;
        } else {
            unset($_COOKIE[self::NAME]);
        }
    }
}
