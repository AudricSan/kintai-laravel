<?php

declare(strict_types=1);

/**
 * Lancé par Composer après chaque génération de l'autoloader (post-autoload-dump) : ajoute en tête de
 * vendor/autoload.php le chargement de bootstrap/helpers.php, avant les helpers de Laravel. Voir ce fichier.
 *
 * Idempotent : sans effet si la ligne est déjà là.
 */

$root     = dirname(__DIR__, 2);
$autoload = $root . '/vendor/autoload.php';
$line     = "require_once dirname(__DIR__) . '/bootstrap/helpers.php'; // Kintai : voir bootstrap/helpers.php\n";

$code = (string) file_get_contents($autoload);
if (str_contains($code, '/bootstrap/helpers.php')) {
    return;
}

$code = preg_replace('/^<\?php\s*\n/', "<?php\n\n" . $line, $code, 1, $count);
if ($count !== 1) {
    fwrite(STDERR, "vendor/autoload.php : en-tête <?php introuvable, bootstrap/helpers.php non branché.\n");
    exit(1);
}

file_put_contents($autoload, $code);
