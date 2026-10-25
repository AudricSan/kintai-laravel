<?php

declare(strict_types=1);

// Les tests tournent contre le vrai Kintai (Laravel), cloné à côté de ce dépôt (../../kintai-laravel, installé avec
// composer install) ou désigné par KINTAI_CORE : son autoloader apporte les classes du Core, ses helpers et Laravel,
// dans le même ordre que l'application. Celui de ce dépôt (PHPUnit et les classes du bundle) repasse ensuite en tête,
// pour que PHPUnit ne vienne que d'ici.
$coreDir = getenv('KINTAI_CORE') ?: dirname(__DIR__, 3) . '/kintai-laravel';
$core    = rtrim($coreDir, '/\\') . '/vendor/autoload.php';
if (!is_file($core)) {
    fwrite(STDERR, "Kintai introuvable : clonez kintai-laravel dans ../../kintai-laravel (ou indiquez KINTAI_CORE) et lancez-y composer install.\n");
    exit(1);
}
require $core;

// Racine du Core, comme dans l'application (traductions du Core, fichiers lus par les vues…).
if (!defined('BASE_PATH')) {
    define('BASE_PATH', (string) realpath(dirname($core, 2)));
}

$bundle = require dirname(__DIR__) . '/vendor/autoload.php';
$bundle->unregister();
$bundle->register(true);
