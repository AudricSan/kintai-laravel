#!/usr/bin/env php
<?php

/**
 * Contrôle un dossier de wiki avant publication (voir WikiIntegrityChecker) :
 *
 *   php scripts/check-wiki.php <dossier-du-wiki>     ex. un clone de Kintai.wiki.git, ou .wiki/
 *
 * Pages de la barre latérale présentes en anglais, français et japonais, ligne de langue, liens internes valides.
 * Code de sortie : 1 s'il y a des erreurs (les avertissements ne comptent pas).
 */

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/vendor/autoload.php';
require BASE_PATH . '/bootstrap/standalone.php';

use kintai\Core\Services\WikiIntegrityChecker;

$path = $argv[1] ?? (BASE_PATH . '/.wiki');
if (!is_dir($path)) {
    fwrite(STDERR, "Dossier introuvable : $path\n");
    exit(2);
}

$result = (new WikiIntegrityChecker())->check($path);

foreach ($result['warnings'] as $w) {
    echo "⚠ $w\n";
}
foreach ($result['errors'] as $e) {
    echo "✗ $e\n";
}
printf("\n%d erreur(s), %d avertissement(s) — %s\n", count($result['errors']), count($result['warnings']), $path);
exit($result['errors'] === [] ? 0 : 1);
