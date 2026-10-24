<?php

declare(strict_types=1);

// Les tests tournent contre le vrai Kintai, cloné à côté de ce dépôt (../../Kintai, installé avec composer install) :
// son autoloader apporte les classes du Core, ses helpers et Laravel, dans le même ordre que l'application. Celui de
// ce dépôt (PHPUnit et les classes du bundle) repasse ensuite en tête, pour que PHPUnit ne vienne que d'ici.
$core = dirname(__DIR__, 3) . '/Kintai/vendor/autoload.php';
if (!is_file($core)) {
    fwrite(STDERR, "Kintai introuvable : clonez-le dans ../../Kintai et lancez-y composer install.\n");
    exit(1);
}
require $core;

$bundle = require dirname(__DIR__) . '/vendor/autoload.php';
$bundle->unregister();
$bundle->register(true);
