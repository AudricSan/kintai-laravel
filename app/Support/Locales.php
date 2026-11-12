<?php

namespace App\Support;

/**
 * Langues proposées aux utilisateurs.
 */
final class Locales
{
    /** @var array<string, string> code => nom dans sa propre langue */
    public const SUPPORTED = [
        'fr' => 'Français',
        'en' => 'English',
        'ja' => '日本語',
    ];

    public static function isSupported(?string $locale): bool
    {
        return $locale !== null && array_key_exists($locale, self::SUPPORTED);
    }
}
