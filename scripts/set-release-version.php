<?php

declare(strict_types=1);

/**
 * Écrit la version d'une release dans le champ 'version' de config/app.php.
 *
 * Appelé par .github/workflows/release.yml juste avant de poser le tag : l'archive publiée (zipball) porte ainsi
 * la version réelle. Sans cela, une installation neuve depuis l'archive se croirait dans la version littérale
 * figée dans le dépôt, et l'installateur de bundles (kintai_core.min) refuserait tout bundle exigeant une
 * version plus récente. Les instances déjà installées ne sont pas concernées : GithubUpdateService réécrit ce
 * même champ à chaque mise à jour appliquée.
 *
 * Réutilise UpdateService::setCurrentVersion() — même expression régulière que le programme de mise à jour,
 * donc une seule définition de « où vit la version ». config/app.php n'est pas chargé (il appelle env()).
 *
 * Usage : php scripts/set-release-version.php X.Y.Z [chemin-racine]
 */

require_once __DIR__ . '/../app/Services/UpdateService.php';

use App\Services\UpdateService;

$version = $argv[1] ?? '';
$base = rtrim($argv[2] ?? dirname(__DIR__), '/\\');

if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
    fwrite(STDERR, "Version invalide « {$version} » : attendu X.Y.Z (sans préfixe v).\n");
    exit(1);
}

$file = $base . '/config/app.php';
if (!is_file($file)) {
    fwrite(STDERR, "Fichier introuvable : {$file}\n");
    exit(1);
}

// Le champ doit être un littéral : un env('APP_VERSION', …) serait laissé tel quel par la substitution.
$literal = "/'version'\s*=>\s*'[^']*'/";
if (preg_match($literal, (string) file_get_contents($file)) !== 1) {
    fwrite(STDERR, "Champ 'version' littéral introuvable dans {$file} : version non écrite.\n");
    exit(1);
}

(new UpdateService($base))->setCurrentVersion($version);

// Relecture : ne jamais publier une release dont l'archive affirme une autre version.
if (preg_match("/'version'\s*=>\s*'" . preg_quote($version, '/') . "'/", (string) file_get_contents($file)) !== 1) {
    fwrite(STDERR, "La version {$version} n'a pas été écrite dans {$file}.\n");
    exit(1);
}

echo "config/app.php : version = {$version}\n";
