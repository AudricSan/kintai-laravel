<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\WikiIntegrityChecker;
use Illuminate\Console\Command;

/**
 * Contrôle un dossier de wiki avant publication (voir WikiIntegrityChecker) :
 *
 *   php artisan kintai:check-wiki <dossier-du-wiki>     ex. un clone de Kintai.wiki.git, ou .wiki/
 *
 * Pages de la barre latérale présentes en anglais, français et japonais, ligne de langue, liens internes valides.
 * Code de sortie : 1 s'il y a des erreurs (les avertissements ne comptent pas).
 */
final class CheckWikiCommand extends Command
{
    protected $signature = 'kintai:check-wiki {path? : Dossier du wiki (défaut : .wiki)}';

    protected $description = 'Contrôle un dossier de wiki avant publication';

    public function handle(): int
    {
        $path = (string) ($this->argument('path') ?? base_path('.wiki'));
        if (!is_dir($path)) {
            $this->error("Dossier introuvable : {$path}");

            return 2;
        }

        $result = (new WikiIntegrityChecker())->check($path);

        foreach ($result['warnings'] as $warning) {
            $this->line("⚠ {$warning}");
        }
        foreach ($result['errors'] as $error) {
            $this->line("✗ {$error}");
        }
        $this->line(sprintf("\n%d erreur(s), %d avertissement(s) — %s", count($result['errors']), count($result['warnings']), $path));

        return $result['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
