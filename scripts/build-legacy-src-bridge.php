<?php

declare(strict_types=1);

/**
 * Ajoute à une archive de release un relais src/ → app/ pour chaque classe du cœur.
 *
 * Avec Laravel, le code de Kintai est passé de src/ à app/. Une instance 0.x qui se met à jour par le bouton
 * « Mettre à jour » (GithubUpdateService) copie les fichiers de la nouvelle release puis, dans la même requête, lance
 * les migrations, journalise la mise à jour et sort du mode maintenance. Cette requête tourne encore avec l'ancien
 * chargeur de classes, qui cherche chaque classe sous src/ : sans relais, la première classe pas encore chargée la
 * fait échouer après la copie, et l'instance reste en maintenance.
 *
 * Chaque relais src/X.php inclut app/X.php (require_once : jamais deux définitions). La nouvelle application ne les
 * lit jamais, elle charge app/ directement. Les fichiers déjà présents sous src/ (anciens chemins gardés dans le dépôt,
 * comme les partiels de vues ou le contrat) sont conservés tels quels.
 *
 * Appelé par .github/workflows/release.yml sur le dossier de l'archive, jamais sur le dépôt.
 *
 * Usage : php scripts/build-legacy-src-bridge.php DOSSIER-DE-L-ARCHIVE
 */

$root = rtrim($argv[1] ?? '', '/\\');
if ($root === '' || !is_dir($root . '/app')) {
    fwrite(STDERR, "Dossier d'archive invalide « {$root} » : app/ introuvable.\n");
    exit(1);
}

$written = 0;
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app', FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root . '/app/')));
    // Les vues ne sont pas des classes : leurs anciens chemins utiles sont déjà gardés sous src/UI/View/_partials.
    if (!str_ends_with($relative, '.php') || str_ends_with($relative, '.blade.php') || str_starts_with($relative, 'UI/View/')) {
        continue;
    }
    if (preg_match('/^\s*(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s+\w+/m', (string) file_get_contents($file->getPathname())) !== 1) {
        continue;
    }

    $target = $root . '/src/' . $relative;
    if (file_exists($target)) {
        continue;
    }
    if (!is_dir(dirname($target))) {
        mkdir(dirname($target), 0755, true);
    }

    $depth = substr_count($relative, '/') + 1;
    file_put_contents($target, "<?php\n\n"
        . "// Relais de l'archive de release pour les instances 0.x qui se mettent à jour (src/ est devenu app/) :\n"
        . "// voir scripts/build-legacy-src-bridge.php.\n"
        . "require_once dirname(__DIR__, {$depth}) . '/app/{$relative}';\n");
    $written++;
}

echo "{$written} relais src/ → app/ écrits.\n";
