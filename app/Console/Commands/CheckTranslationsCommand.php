<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Vérifie que chaque document traduisible a bien ses pendants .fr.md et .ja.md, et signale si une traduction est
 * plus ancienne que la version anglaise source.
 *
 * Non bloquant : la commande réussit toujours, elle sert à faire remonter un rapport lisible en CI ou en local.
 */
final class CheckTranslationsCommand extends Command
{
    /** Documents sources ; leurs traductions vivent toutes dans docs/i18n/. */
    private const BASE_DOCS = ['README.md', 'CHANGELOG.md', 'CONTRIBUTING.md', 'SECURITY.md'];

    private const LANGUAGES = ['fr' => 'Français', 'ja' => '日本語'];

    protected $signature = 'kintai:check-translations';

    protected $description = 'Vérifie les traductions de la documentation (rapport informatif)';

    public function handle(): int
    {
        $root = base_path();
        $missing = $stale = $ok = [];

        foreach (self::BASE_DOCS as $baseDoc) {
            if (!is_file($root . '/' . $baseDoc)) {
                continue;
            }
            $baseTimestamp = $this->lastCommitTimestamp($root, $baseDoc);
            $name          = pathinfo($baseDoc, PATHINFO_FILENAME);

            foreach (self::LANGUAGES as $code => $label) {
                $translated = "docs/i18n/{$name}.{$code}.md";
                if (!is_file($root . '/' . $translated)) {
                    $missing[] = "{$translated} ({$label}) — traduction de {$baseDoc} manquante";
                    continue;
                }

                $translatedTimestamp = $this->lastCommitTimestamp($root, $translated);
                if ($baseTimestamp !== null && $translatedTimestamp !== null && $translatedTimestamp < $baseTimestamp) {
                    $days    = (int) round(($baseTimestamp - $translatedTimestamp) / 86400);
                    $stale[] = "{$translated} ({$label}) — potentiellement obsolète, {$baseDoc} modifié plus récemment (~{$days}j d'écart)";
                    continue;
                }
                $ok[] = "{$translated} ({$label})";
            }
        }

        $this->line('=== Vérification des traductions (FR / JA) ===');
        $this->newLine();
        if ($missing !== []) {
            $this->line('❌ Manquantes (' . count($missing) . ') :');
            array_map(fn(string $line) => $this->line("   - {$line}"), $missing);
            $this->newLine();
        }
        if ($stale !== []) {
            $this->line('⚠️  Potentiellement obsolètes (' . count($stale) . ') :');
            array_map(fn(string $line) => $this->line("   - {$line}"), $stale);
            $this->newLine();
        }
        $this->line('✅ À jour (' . count($ok) . '/' . (count(self::BASE_DOCS) * count(self::LANGUAGES)) . ')');
        if ($missing === [] && $stale === []) {
            $this->line('Toutes les traductions sont présentes et à jour.');
        }

        return self::SUCCESS;
    }

    /** Date du dernier commit qui touche ce fichier ; null s'il n'est pas encore commité. */
    private function lastCommitTimestamp(string $root, string $relativePath): ?int
    {
        $output   = [];
        $exitCode = 0;
        $null     = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        exec('git -C ' . escapeshellarg($root) . ' log -1 --format=%ct -- ' . escapeshellarg($relativePath) . " 2>{$null}", $output, $exitCode);

        return $exitCode !== 0 || empty($output[0]) ? null : (int) $output[0];
    }
}
