<?php

declare(strict_types=1);

/**
 * Usage : php tools/release/build.php <tag> [--output=dist] [--min-from-version=X.Y.Z]
 *
 * `<tag>` est le tag Git (`v1.2.3` ou `1.2.3`). Il doit correspondre à `kintai.version` de config/kintai.php.
 * Produit `dist/kintai-X.Y.Z.zip` et `dist/kintai-X.Y.Z.zip.sha256`.
 */
require __DIR__.'/ReleaseBuilder.php';

$root = dirname(__DIR__, 2);
$tag = null;
$output = $root.'/dist';
$minFrom = null;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--output=')) {
        $output = substr($argument, 9);
    } elseif (str_starts_with($argument, '--min-from-version=')) {
        $minFrom = substr($argument, 19);
    } elseif ($tag === null) {
        $tag = $argument;
    }
}

if ($tag === null) {
    fwrite(STDERR, "Usage : php tools/release/build.php <tag> [--output=dist] [--min-from-version=X.Y.Z]\n");
    exit(2);
}

$version = ltrim($tag, 'v');

// Garde-fou : la version annoncée par l'application doit être celle de la release.
$config = (string) file_get_contents($root.'/config/kintai.php');

if (preg_match("/'version'\s*=>\s*'([^']+)'/", $config, $match) !== 1 || $match[1] !== $version) {
    fwrite(STDERR, 'config/kintai.php annonce la version '.($match[1] ?? '?')." ; le tag demande {$version}. Mettre la constante à jour avant de taguer.\n");
    exit(1);
}

try {
    $result = (new ReleaseBuilder($root))->build($version, $output, $minFrom);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
}

fwrite(STDOUT, "{$result['zip']} ({$result['files']} fichiers)\n{$result['checksum']}\n");
