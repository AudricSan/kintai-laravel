<?php

declare(strict_types=1);

// Chargeur minimal de l'outil : aucune dépendance vers app/ ni vers Composer.
spl_autoload_register(static function (string $class): void {
    $prefix = 'KintaiLegacy\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__.'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
