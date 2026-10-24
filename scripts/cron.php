#!/usr/bin/env php
<?php

/**
 * Lance une tâche planifiée en ligne de commande, sans jeton : pour un serveur avec accès SSH, depuis la crontab
 * locale. Toutes les tâches sont disponibles, celles du Core comme celles des bundles actifs (rapports journaliers,
 * photos…).
 *
 * ─── Usage ────────────────────────────────────────────────────────────────────
 *
 *   php scripts/cron.php                    Liste des tâches disponibles
 *   php scripts/cron.php <tâche>            Lance la tâche
 *   php scripts/cron.php <tâche> --cle=val  Lance la tâche avec des paramètres (les mêmes que ceux de l'URL),
 *                                           ex. php scripts/cron.php auto-validate --date=2026-10-13 --all=1
 *
 * ─── Crontab (serveur avec accès SSH) ─────────────────────────────────────────
 *
 *   crontab -e
 *   0 * * * * php /var/www/html/scripts/cron.php auto-validate >> /var/log/kintai-cron.log 2>&1
 *   0 3 * * * php /var/www/html/scripts/cron.php backup        >> /var/log/kintai-cron.log 2>&1
 *
 * ─── Hébergement mutualisé (sans accès SSH) ───────────────────────────────────
 *
 *   Faire appeler par les tâches planifiées de l'hébergeur l'URL de la tâche, avec un jeton cron
 *   (php scripts/create-cron-token.php, ou la page d'administration) :
 *   https://votresite.com/cron/run/<tâche>?token=VOTRE_JETON
 *
 * Code de sortie : 0 si la tâche réussit, 1 sinon (réponse en erreur ou tâche inconnue).
 */

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/vendor/autoload.php';
require BASE_PATH . '/bootstrap/standalone.php';
require BASE_PATH . '/app/Core/helpers.php';

use kintai\Core\Application;
use kintai\Core\Cron\CronRunner;
use kintai\Core\InstalledBundleAutoloader;
use kintai\Core\Request;

// Même démarrage que le front controller (public/index.php) : fichier .env, puis classes des bundles installés,
// sans lesquelles les tâches des bundles ne seraient pas disponibles.
kintai_load_env_file(BASE_PATH);
(new InstalledBundleAutoloader())->register();

if (!file_exists(storage_path('installed.lock'))) {
    fwrite(STDERR, '[Kintai] Application non installée : lancez d\'abord l\'installateur (public/install.php).' . PHP_EOL);
    exit(1);
}

$jobName = $argv[1] ?? '';

// Paramètres --cle=valeur (ou --cle seul = 1), transmis à la tâche comme ceux de l'URL.
$params = [];
foreach (array_slice($argv, 2) as $arg) {
    if (preg_match('/^--([a-z0-9_-]+)(?:=(.*))?$/i', $arg, $m) === 1) {
        $params[$m[1]] = $m[2] ?? '1';
    } else {
        fwrite(STDERR, "[Kintai] Paramètre ignoré : {$arg} (attendu : --cle=valeur)" . PHP_EOL);
    }
}

$app = new Application(BASE_PATH);
$app->boot();

// Hors requête HTTP, aucun middleware ne choisit la langue : celle par défaut de l'instance (libellés des tâches,
// textes produits par les tâches).
$defaultLanguage = $app->container()->make(\kintai\Core\Repositories\LanguageRepositoryInterface::class)->findDefault();
$app->container()->make(\kintai\Core\Services\TranslationService::class)->setLocale((string) ($defaultLanguage['code'] ?? 'en'));

/** @var CronRunner $runner */
$runner = $app->container()->make(CronRunner::class);

if ($jobName === '' || $jobName === '--help' || $jobName === '-h' || $runner->getJob($jobName) === null) {
    if (!in_array($jobName, ['', '--help', '-h'], true)) {
        fwrite(STDERR, "[Kintai] Tâche inconnue : {$jobName} (bundle désactivé ou absent ?)" . PHP_EOL . PHP_EOL);
    }
    echo 'Usage : php scripts/cron.php <tâche> [--cle=valeur …]' . PHP_EOL . PHP_EOL . 'Tâches disponibles :' . PHP_EOL;
    foreach ($runner->getJobMeta() as $meta) {
        echo sprintf('  %-16s %s', $meta['name'], $meta['label']) . PHP_EOL;
    }
    exit(in_array($jobName, ['--help', '-h'], true) ? 0 : 1);
}

// Requête équivalente à l'appel HTTP de la tâche (les tâches lisent leurs paramètres dans la requête).
$_GET = $params;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/cron/run/' . rawurlencode($jobName) . ($params === [] ? '' : '?' . http_build_query($params));
$_SERVER['REMOTE_ADDR'] ??= '127.0.0.1';

$started = date('Y-m-d H:i:s');
try {
    $response = $runner->runLocal($jobName, new Request());
} catch (\Throwable $e) {
    fwrite(STDERR, sprintf('[%s] %s : échec — %s', $started, $jobName, $e->getMessage()) . PHP_EOL);
    exit(1);
}

echo sprintf('[%s] %s : %s', $started, $jobName, $response->body()) . PHP_EOL;
exit($response->status() < 400 ? 0 : 1);
