<?php

declare(strict_types=1);

/**
 * Application Laravel complète (environnement, configuration, fournisseurs, bundles) pour les points d'entrée qui ne
 * passent ni par le noyau HTTP ni par Artisan : scripts/ et l'installateur. À charger après vendor/autoload.php.
 * Renvoie l'application.
 */

$app = require __DIR__ . '/standalone.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

return $app;
