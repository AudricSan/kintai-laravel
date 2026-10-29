<?php

declare(strict_types=1);

use Illuminate\Support\Str;

return [
    'driver' => env('SESSION_DRIVER', 'file'),

    // En minutes (SESSION_LIFETIME valait aussi 7200, en secondes, avant la migration : 2 heures).
    'lifetime' => (int) env('SESSION_LIFETIME', 120),

    'expire_on_close' => false,

    'encrypt' => false,

    'files' => storage_path('framework/sessions'),

    'connection' => env('SESSION_CONNECTION'),

    'table' => 'sessions',

    'store' => env('SESSION_STORE'),

    'lottery' => [2, 100],

    'cookie' => env('SESSION_NAME', Str::slug((string) env('APP_NAME', 'kintai'), '_') . '_session'),

    'path' => '/',

    'domain' => env('SESSION_DOMAIN'),

    // null : Laravel suit alors le schéma de la requête (HTTPS ou non), comme le faisait Kintai.
    'secure' => env('SESSION_SECURE'),

    'http_only' => true,

    'same_site' => env('SESSION_SAMESITE', 'lax'),

    'partitioned' => false,
];
