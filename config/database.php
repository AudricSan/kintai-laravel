<?php

declare(strict_types=1);

/**
 * Base de données de l'instance : variables DB_* du fichier .env, que l'installateur écrit. Pilotes : sqlite (par défaut,
 * storage/app/database.sqlite) et mysql.
 */
return [
    'default' => env('DB_CONNECTION', 'sqlite'),

    'connections' => [
        'sqlite' => [
            'driver'   => 'sqlite',
            'database' => env('DB_DATABASE') ?: storage_path('app/database.sqlite'),
            'prefix'   => '',
            // Règles onDelete appliquées comme sous MySQL : sans cela, supprimer une ligne laissait ses dépendances
            // orphelines sous SQLite et les supprimait sous MySQL.
            'foreign_key_constraints' => true,
        ],

        'mysql' => [
            'driver'    => 'mysql',
            'host'      => env('DB_HOST', '127.0.0.1'),
            'port'      => (int) env('DB_PORT', 3306),
            'database'  => env('DB_DATABASE', 'kintai'),
            'username'  => env('DB_USERNAME', 'root'),
            'password'  => env('DB_PASSWORD', ''),
            'charset'   => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix'    => env('DB_PREFIX', ''),
        ],
    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],
];
