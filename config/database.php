<?php

declare(strict_types=1);

use kintai\Core\Database\DatabaseConfig;

/**
 * Base de données de l'instance, pour Laravel (DB::, Eloquent, Artisan).
 *
 * La connexion vient des fichiers de Kintai (voir DatabaseConfig) : valeurs par défaut et variables DB_*, surchargées
 * par config/database.local.php que l'installateur écrit (à ne jamais versionner). Pilotes : sqlite, mysql.
 */

[$driver, $connection] = DatabaseConfig::resolve(__DIR__);

return [
    'default' => $driver,

    'connections' => [
        $driver => $connection,
    ],

    // Kintai suit ses propres migrations dans la table « migrations » (MigrationRunner) : celles de Laravel, s'il y en
    // a un jour, sont suivies à part.
    'migrations' => [
        'table' => 'laravel_migrations',
        'update_date_on_publish' => true,
    ],
];
