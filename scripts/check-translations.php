<?php

declare(strict_types=1);

/**
 * Vérifie que chaque document traduisible a bien ses pendants .fr.md et .ja.md,
 * et signale si une traduction est plus ancienne que la version anglaise source.
 *
 * Non bloquant : ce script ne fait jamais échouer le process qui l'appelle (exit 0),
 * il sert uniquement à faire remonter un rapport lisible en CI ou en local.
 *
 * Usage : php scripts/check-translations.php
 */

$root = dirname(__DIR__);

$baseDocs = [
    'README.md',
    'CHANGELOG.md',
    'CONTRIBUTING.md',
    'SECURITY.md',
];

// Toutes les traductions vivent dans docs/i18n/, quel que soit le dossier du document source.
$translatedDir = 'docs/i18n/';

$languages = [
    'fr' => 'Français',
    'ja' => '日本語',
];

function lastCommitTimestamp(string $root, string $relativePath): ?int
{
    $nullDevice = str_starts_with(PHP_OS_FAMILY, 'Windows') ? 'NUL' : '/dev/null';
    $path = escapeshellarg($relativePath);
    $output = [];
    $exitCode = 0;
    exec("git -C " . escapeshellarg($root) . " log -1 --format=%ct -- {$path} 2>{$nullDevice}", $output, $exitCode);

    if ($exitCode !== 0 || empty($output[0])) {
        return null; // pas encore commité, ou pas d'historique — on ne peut rien comparer
    }

    return (int) $output[0];
}

$missing = [];
$stale = [];
$ok = [];

foreach ($baseDocs as $baseDoc) {
    $baseAbsolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $baseDoc);

    if (!is_file($baseAbsolute)) {
        continue; // document source lui-même absent — hors périmètre de ce script
    }

    $baseTimestamp = lastCommitTimestamp($root, $baseDoc);
    $pathInfo = pathinfo($baseDoc);
    $filenameWithoutExt = $pathInfo['filename'];

    foreach ($languages as $code => $label) {
        $translatedRelative = "{$translatedDir}{$filenameWithoutExt}.{$code}.md";
        $translatedAbsolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $translatedRelative);

        if (!is_file($translatedAbsolute)) {
            $missing[] = "{$translatedRelative} ({$label}) — traduction de {$baseDoc} manquante";
            continue;
        }

        $translatedTimestamp = lastCommitTimestamp($root, $translatedRelative);

        if ($baseTimestamp !== null && $translatedTimestamp !== null && $translatedTimestamp < $baseTimestamp) {
            $staleDays = (int) round(($baseTimestamp - $translatedTimestamp) / 86400);
            $stale[] = "{$translatedRelative} ({$label}) — potentiellement obsolète, {$baseDoc} modifié plus récemment (~{$staleDays}j d'écart)";
            continue;
        }

        $ok[] = "{$translatedRelative} ({$label})";
    }
}

echo "=== Vérification des traductions (FR / JA) ===\n\n";

if ($missing !== []) {
    echo "❌ Manquantes (" . count($missing) . ") :\n";
    foreach ($missing as $line) {
        echo "   - {$line}\n";
    }
    echo "\n";
}

if ($stale !== []) {
    echo "⚠️  Potentiellement obsolètes (" . count($stale) . ") :\n";
    foreach ($stale as $line) {
        echo "   - {$line}\n";
    }
    echo "\n";
}

echo "✅ À jour (" . count($ok) . "/" . (count($baseDocs) * count($languages)) . ")\n";

if ($missing === [] && $stale === []) {
    echo "\nToutes les traductions sont présentes et à jour.\n";
}

// Toujours 0 : ce contrôle est informatif, jamais bloquant.
exit(0);
