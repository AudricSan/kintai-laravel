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

    // null : le cookie est « Secure » dès que la requête est en HTTPS (KintaiServiceProvider::boot) ; false le désactive.
    'secure' => env('SESSION_SECURE') === null ? null : filter_var(env('SESSION_SECURE'), FILTER_VALIDATE_BOOL),

    'http_only' => true,

    'same_site' => env('SESSION_SAMESITE', 'lax'),

    'partitioned' => false,
];
