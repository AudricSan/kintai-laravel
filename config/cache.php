<?php

declare(strict_types=1);

/**
 * Cache de Laravel. Sert aux verrous du planificateur (une tâche longue n'est pas relancée par-dessus elle-même) ;
 * fichiers sous storage/framework/cache, rien à installer. CACHE_STORE=array le désactive (tests).
 */
return [
    'default' => env('CACHE_STORE', 'file'),

    'stores' => [
        'array' => [
            'driver'    => 'array',
            'serialize' => false,
        ],

        'file' => [
            'driver'    => 'file',
            'path'      => storage_path('framework/cache/data'),
            'lock_path' => storage_path('framework/cache/data'),
        ],
    ],

    'prefix' => env('CACHE_PREFIX', 'kintai_cache_'),
];
