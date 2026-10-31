<?php

declare(strict_types=1);

return [
    'name' => env('APP_NAME', 'Kintai'),
    'debug' => env('APP_DEBUG', false),
    'timezone' => env('APP_TIMEZONE', 'Asia/Tokyo'),
    // Langue de l'instance (installateur, /admin/owner-settings) : celle des textes quand l'utilisateur n'a pas choisi la sienne.
    'locale' => env('APP_LOCALE', 'en'),
    'fallback_locale' => env('APP_LOCALE', 'en'),
    'url' => env('APP_URL', ''),
    // Clé de chiffrement (jeton CSRF, cookies chiffrés) : écrite dans .env par l'installateur.
    'key' => env('APP_KEY'),
    'cipher' => 'AES-256-CBC',
    'version' => '11.0.0',
];