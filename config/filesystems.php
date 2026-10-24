<?php

declare(strict_types=1);

return [
    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [
        // Pas de route « storage/{path} » servie par Laravel : les fichiers de storage/ passent par la route
        // /storage/{path*} de Kintai (StorageFileController), qui contrôle l'accès.
        'local' => [
            'driver' => 'local',
            'root'   => storage_path('app'),
            'serve'  => false,
            'throw'  => false,
            'report' => false,
        ],
    ],
];
