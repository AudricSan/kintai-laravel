<?php

declare(strict_types=1);

return [
    'name' => env('SESSION_NAME', 'kintai_session'),
    'lifetime' => env('SESSION_LIFETIME', 7200), // 2 hours
    'path' => '/',
    'secure' => env('SESSION_SECURE', false), // Passe automatiquement à true si la requête est en HTTPS
    'httponly' => env('SESSION_HTTPONLY', true),
    'samesite' => env('SESSION_SAMESITE', 'Lax'),
];
