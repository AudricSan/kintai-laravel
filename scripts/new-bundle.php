<?php

declare(strict_types=1);

/**
 * Génère le squelette d'un bundle conforme au contrat (classe, routes, vue, traductions fr/en/ja préfixées, tests de
 * conformité, CI, README) à partir de scripts/bundle-template/.
 *
 *   php scripts/new-bundle.php daily-report-extra --name="Rapport étendu" [--dir=../KintaiBundleDev] [--author=AudricSan]
 *
 * Crée {dir}/kintai-bundle-{slug}/ (dir par défaut : le dossier parent de Kintai/KintaiBundleDev si présent, sinon le
 * dossier courant). Refuse d'écraser un dossier existant. Le dépôt GitHub « kintai-bundle-template » n'est que ce même
 * dossier généré une fois pour un exemple, pour que les deux ne divergent jamais.
 */

use kintai\Contract\Contract;

require_once dirname(__DIR__) . '/app/Contract/Contract.php';

$options = ['name' => null, 'dir' => null, 'author' => 'AudricSan'];
$slug    = null;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(name|dir|author)=(.*)$/', $arg, $m)) {
        $options[$m[1]] = $m[2];
    } elseif ($slug === null && !str_starts_with($arg, '--')) {
        $slug = $arg;
    } else {
        fwrite(STDERR, "Argument inconnu : $arg\n");
        exit(2);
    }
}

if ($slug === null || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
    fwrite(STDERR, "Usage : php scripts/new-bundle.php <slug> [--name=\"Nom\"] [--dir=chemin] [--author=Nom]\n"
        . "Le slug est en minuscules, chiffres et tirets (ex. daily-report).\n");
    exit(2);
}

$studly = str_replace(' ', '', ucwords(str_replace('-', ' ', $slug)));
$prefix = str_replace('-', '_', $slug);
$label  = $options['name'] ?? ucwords(str_replace('-', ' ', $slug));
[$major, $minor] = array_map('intval', explode('.', Contract::VERSION));

$vars = [
    '{{slug}}'        => $slug,
    '{{Studly}}'      => $studly,
    '{{prefix}}'      => $prefix,
    '{{prefixDot}}'   => str_replace('_', '.', $prefix),
    '{{Label}}'       => addcslashes((string) $label, '"\\'),
    '{{author}}'      => (string) $options['author'],
    '{{authorLower}}' => strtolower((string) $options['author']),
    '{{contract}}'    => $major . '.' . $minor,
];

$target = rtrim((string) ($options['dir'] ?? (is_dir(dirname(__DIR__, 2) . '/KintaiBundleDev') ? dirname(__DIR__, 2) . '/KintaiBundleDev' : getcwd())), '/\\')
    . '/kintai-bundle-' . $slug;
if (file_exists($target)) {
    fwrite(STDERR, "Le dossier $target existe déjà : rien n'a été écrit.\n");
    exit(1);
}

$template = __DIR__ . '/bundle-template';
$map = [
    'src/Bundle.php'                    => "src/{$studly}Bundle.php",
    'src/Controllers/Web/Controller.php' => "src/Controllers/Web/{$studly}Controller.php",
    'Views/index.php'                   => 'Views/index.php',
    'gitignore'                         => '.gitignore',
    'tests/ContractTest.php'            => 'tests/ContractTest.php',
];

$written = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($template, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if (!str_ends_with($file->getFilename(), '.tpl')) {
        continue;
    }
    $relative = substr(str_replace('\\', '/', substr($file->getPathname(), strlen($template) + 1)), 0, -4);
    $dest     = $target . '/' . ($map[$relative] ?? $relative);
    if (basename($dest) === '.gitkeep') {
        $dest = dirname($dest) . '/.gitkeep';
    }
    if (!is_dir(dirname($dest))) {
        mkdir(dirname($dest), 0755, true);
    }
    file_put_contents($dest, strtr((string) file_get_contents($file->getPathname()), $vars));
    $written[] = substr($dest, strlen($target) + 1);
}
sort($written);

echo "Bundle « $slug » créé dans $target (" . count($written) . " fichiers) :\n";
foreach ($written as $f) {
    echo "  $f\n";
}
echo "\nSuite : cd $target && composer install && vendor/bin/phpunit\n"
    . "Puis : checklist de création dans le wiki (Développeurs › Créer un bundle).\n";
