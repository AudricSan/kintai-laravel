<?php

declare(strict_types=1);

/**
 * Migration à sens unique d'une instance de l'ancienne version de Kintai vers le nouveau Core.
 * Voir tools/legacy-migration/README.md. Autonome : aucune dépendance vers app/ ni vers Composer.
 */
require __DIR__.'/autoload.php';

use KintaiLegacy\Importer;
use KintaiLegacy\MigrationException;
use KintaiLegacy\Source;
use KintaiLegacy\Target;

const USAGE = <<<'TXT'
Usage :
  php tools/legacy-migration/migrate.php --source=DOSSIER_ANCIENNE_INSTANCE [options]
  php tools/legacy-migration/migrate.php --source-db=FICHIER.sqlite [--source-uploads=DOSSIER] [options]

Options :
  --source=DIR          dossier de l'ancienne instance (base lue dans config/database.local.php, avatars dans storage/uploads)
  --source-db=FILE      base SQLite de l'ancienne instance (au lieu de --source)
  --source-uploads=DIR  dossier « uploads » de l'ancienne instance (contient avatars/), avec --source-db
  --target-env=FILE     .env du nouveau projet, dont la base est écrite (défaut : .env à la racine du projet)
  --timezone=ZONE       fuseau horaire de l'ancienne instance (ex. Asia/Tokyo) ; défaut : son réglage app_timezone
  --dry-run             exécute tout puis annule : rien n'est écrit, le rapport est affiché
  --help                cette aide

La cible doit être migrée (php artisan migrate) et vide. Faites une sauvegarde avant de lancer l'import.
TXT;

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help' || $argument === '-h') {
        fwrite(STDOUT, USAGE."\n");
        exit(0);
    }
    if ($argument === '--dry-run') {
        $options['dry-run'] = true;

        continue;
    }
    if (preg_match('/^--([a-z\-]+)=(.*)$/s', $argument, $match) === 1) {
        $options[$match[1]] = $match[2];

        continue;
    }
    fwrite(STDERR, "Option inconnue : {$argument}\n\n".USAGE."\n");
    exit(2);
}

try {
    if (isset($options['source']) === isset($options['source-db'])) {
        throw new MigrationException("Indiquez exactement une source : --source=DOSSIER ou --source-db=FICHIER.\n\n".USAGE);
    }

    $source = isset($options['source'])
        ? Source::fromInstance((string) $options['source'])
        : Source::sqlite((string) $options['source-db'], isset($options['source-uploads']) ? (string) $options['source-uploads'] : null);

    $timezone = isset($options['timezone']) ? (string) $options['timezone'] : Importer::sourceTimezone($source);
    if ($timezone === null) {
        throw new MigrationException("Fuseau horaire de l'ancienne instance inconnu : indiquez --timezone=Zone/Ville (ex. Asia/Tokyo).");
    }

    $target = Target::fromEnvFile((string) ($options['target-env'] ?? dirname(__DIR__, 2).'/.env'));
    $report = (new Importer($source, $target, $timezone, isset($options['dry-run'])))->run();
} catch (MigrationException $e) {
    fwrite(STDERR, 'Refus : '.$e->getMessage()."\n");
    exit(1);
}

fwrite(STDOUT, $report->render());
exit(0);
