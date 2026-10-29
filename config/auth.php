<?php

declare(strict_types=1);

return [
    // Garde Laravel (auth(), Auth::, @auth) : l'utilisateur connecté de Kintai, voir App\Foundation\Auth\KintaiGuard.
    'defaults' => [
        'guard' => 'kintai',
    ],

    'guards' => [
        'kintai' => [
            'driver' => 'kintai',
        ],
    ],

    'password' => [
        'algo' => PASSWORD_BCRYPT,
        'cost' => 12,
    ],
];
