<?php

declare(strict_types=1);

namespace kintai\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Commande Artisan qui lance un script de scripts/ avec les arguments reçus, tels quels.
 *
 * `php artisan kintai:cron backup` fait exactement ce que fait `php scripts/cron.php backup` (mêmes options, même
 * sortie, même code de sortie) : les scripts restent la référence, utilisés par les crontabs déjà en place.
 */
final class KintaiScriptCommand extends Command
{
    /** Commandes Artisan → script de scripts/ et description. */
    public const SCRIPTS = [
        'kintai:cron'               => ['cron.php', 'Lance une tâche planifiée (sans argument : liste des tâches)'],
        'kintai:cron-token'         => ['create-cron-token.php', 'Crée un jeton pour /cron/run/{tâche}'],
        'kintai:provision'          => ['provision.php', 'Installe une instance en ligne de commande (comme l\'installateur web)'],
        'kintai:seed-demo'          => ['seed-demo-data.php', 'Ajoute des données de démonstration'],
        'kintai:clean-orphans'      => ['clean-orphaned-data.php', 'Nettoie les lignes orphelines d\'une base importée'],
        'kintai:contract'           => ['export-contract.php', 'Compare le contrat de bundle à son instantané (--write : le régénère)'],
        'kintai:new-bundle'         => ['new-bundle.php', 'Génère le squelette d\'un bundle'],
        'kintai:check-wiki'         => ['check-wiki.php', 'Contrôle un dossier de wiki avant publication'],
        'kintai:check-translations' => ['check-translations.php', 'Vérifie les traductions de la documentation'],
        'kintai:favicons'           => ['generate-favicons.php', 'Génère les icônes de l\'application'],
    ];

    public function __construct(string $name, private readonly string $script, string $description)
    {
        parent::__construct($name);
        $this->setDescription($description);
        $this->setHelp("Équivaut à : php scripts/{$script} [arguments]");
        // Arguments et options sont ceux du script : on ne les valide pas ici.
        $this->ignoreValidationErrors();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $args = $input instanceof ArgvInput ? $input->getRawTokens(true) : [];

        $process = proc_open(
            [PHP_BINARY, base_path('scripts/' . $this->script), ...$args],
            [STDIN, STDOUT, STDERR],
            $pipes,
            base_path(),
        );

        return is_resource($process) ? proc_close($process) : self::FAILURE;
    }
}
