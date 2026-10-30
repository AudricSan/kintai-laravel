<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use kintai\Contract\Contract;

/**
 * Génère le squelette d'un bundle conforme au contrat (classe, routes, vue, traductions fr/en/ja préfixées, test de
 * conformité, README) à partir de scripts/bundle-template/, dans bundles/<slug> du monorepo.
 *
 *   php artisan kintai:new-bundle daily-report-extra --name="Rapport étendu" [--dir=chemin] [--author=AudricSan]
 *
 * Refuse d'écraser un dossier existant. Le bundle doit ensuite être déclaré dans l'autoload de composer.json
 * (les deux lignes affichées en fin de commande), puis `composer dump-autoload`.
 */
final class NewBundleCommand extends Command
{
    protected $signature = 'kintai:new-bundle
        {slug : Identifiant du bundle, en minuscules, chiffres et tirets (ex. daily-report)}
        {--name= : Nom affiché}
        {--dir= : Dossier parent (défaut : bundles/)}
        {--author=AudricSan : Auteur}';

    protected $description = 'Génère le squelette d\'un bundle dans bundles/<slug>';

    public function handle(): int
    {
        $slug = (string) $this->argument('slug');
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            $this->error('Le slug est en minuscules, chiffres et tirets (ex. daily-report).');

            return 2;
        }

        $studly = str_replace(' ', '', ucwords(str_replace('-', ' ', $slug)));
        $prefix = str_replace('-', '_', $slug);
        $label  = $this->option('name') ?: ucwords(str_replace('-', ' ', $slug));
        $author = (string) $this->option('author');
        [$major, $minor] = array_map('intval', explode('.', Contract::VERSION));

        $vars = [
            '{{slug}}'        => $slug,
            '{{Studly}}'      => $studly,
            '{{prefix}}'      => $prefix,
            '{{prefixDot}}'   => str_replace('_', '.', $prefix),
            '{{Label}}'       => addcslashes((string) $label, '"\\'),
            '{{author}}'      => $author,
            '{{authorLower}}' => strtolower($author),
            '{{contract}}'    => $major . '.' . $minor,
        ];

        $target = rtrim((string) ($this->option('dir') ?: base_path('bundles')), '/\\') . '/' . $slug;
        if (file_exists($target)) {
            $this->error("Le dossier {$target} existe déjà : rien n'a été écrit.");

            return self::FAILURE;
        }

        $map = [
            'src/Bundle.php'                     => "src/{$studly}Bundle.php",
            'src/Controllers/Web/Controller.php' => "src/Controllers/Web/{$studly}Controller.php",
        ];

        $template = base_path('scripts/bundle-template');
        $written  = [];
        $files    = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($template, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!str_ends_with($file->getFilename(), '.tpl')) {
                continue;
            }
            $relative = substr(str_replace('\\', '/', substr($file->getPathname(), strlen($template) + 1)), 0, -4);
            $dest     = $target . '/' . ($map[$relative] ?? $relative);
            if (!is_dir(dirname($dest))) {
                mkdir(dirname($dest), 0755, true);
            }
            file_put_contents($dest, strtr((string) file_get_contents($file->getPathname()), $vars));
            $written[] = substr($dest, strlen($target) + 1);
        }
        sort($written);

        $this->line("Bundle « {$slug} » créé dans {$target} (" . count($written) . ' fichiers) :');
        foreach ($written as $path) {
            $this->line("  {$path}");
        }
        $this->newLine();
        $this->line('Autoload à ajouter à composer.json (puis composer dump-autoload) :');
        $this->line("  autoload-dev  \"Tests\\\\Bundles\\\\{$studly}\\\\\": \"bundles/{$slug}/tests/\"");
        $this->line("  autoload      \"kintai\\\\Bundles\\\\Installed\\\\{$studly}\\\\\": \"bundles/{$slug}/src/\"");
        $this->line('Puis : checklist de création dans le wiki (Développeurs › Créer un bundle).');

        return self::SUCCESS;
    }
}
