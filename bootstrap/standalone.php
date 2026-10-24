<?php

declare(strict_types=1);

/**
 * Application Laravel pour les points d'entrée qui ne passent pas par le noyau HTTP ni par Artisan : scripts/,
 * public/install.php, socle des tests. Elle est construite sans démarrer l'environnement ni la configuration ; les
 * helpers de Laravel que le code de Kintai appelle (__(), csrf_token(), storage_path()…) trouvent leurs services.
 *
 * À charger après vendor/autoload.php. Renvoie l'application.
 */

$app = require __DIR__ . '/app.php';
$app->register(kintai\Providers\KintaiServiceProvider::class);

return $app;
