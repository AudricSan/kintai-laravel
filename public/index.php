<?php

declare(strict_types=1);

use Illuminate\Http\Request;

define('BASE_PATH', dirname(__DIR__));
define('LARAVEL_START', microtime(true));

require BASE_PATH . '/vendor/autoload.php';
kintai_normalize_script_name();

/** @var Illuminate\Foundation\Application $app */
$app = require_once BASE_PATH . '/bootstrap/app.php';

// Bundles installés dynamiquement (storage/bundles/) : hors PSR-4 standard,
// voir App\InstalledBundleAutoloader.
(new App\InstalledBundleAutoloader())->register();

// Check if the application is installed
$installedLockFile = BASE_PATH . '/storage/installed.lock';
if (!file_exists($installedLockFile)) {
    if (kintai_installed_via_database(BASE_PATH)) {
        // Le marqueur a disparu (hors Git, cause externe non identifiée — voir CHANGELOG)
        // alors que la base de données est bien installée : on le régénère au lieu de
        // forcer un réinstall destructeur à chaque requête.
        @file_put_contents($installedLockFile, bin2hex(random_bytes(32)));
    } else {
        // Réellement pas installé → rediriger vers l'installeur
        header('Location: ' . base_url() . '/install.php');
        exit;
    }
}

$app->handleRequest(Request::capture());
