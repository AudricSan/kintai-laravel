<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Cron\CronRunner;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

/**
 * `php artisan kintai:cron <tâche>` : lance une tâche planifiée sans jeton (l'accès au serveur vaut autorisation).
 * Les tâches du Core comme celles des bundles actifs sont disponibles ; sans argument, la commande les liste.
 *
 *   php artisan kintai:cron auto-validate --param=date=2026-10-13 --param=all=1
 *   php artisan kintai:cron resignation-deactivate --if-available   (rien à faire si le bundle est absent)
 *
 * Hébergement sans accès SSH : l'URL /cron/run/<tâche>?token=… de CronController lance la même tâche.
 */
final class CronCommand extends Command
{
    protected $signature = 'kintai:cron
        {job? : Nom de la tâche (sans argument : liste des tâches disponibles)}
        {--param=* : Paramètre de la tâche, au format cle=valeur (les mêmes que ceux de l\'URL)}
        {--if-available : Ne fait rien (code 0) si la tâche n\'existe pas, p. ex. celle d\'un bundle désactivé}';

    protected $description = 'Lance une tâche planifiée (sans argument : liste des tâches)';

    public function handle(CronRunner $runner): int
    {
        if (!file_exists(storage_path('installed.lock'))) {
            if ($this->option('if-available')) {
                return self::SUCCESS;
            }
            $this->error('Application non installée : lancez d\'abord l\'installateur (public/install.php).');

            return self::FAILURE;
        }

        $name = (string) $this->argument('job');
        if ($name !== '' && $runner->getJob($name) === null && $this->option('if-available')) {
            return self::SUCCESS;
        }

        if ($name === '' || $runner->getJob($name) === null) {
            if ($name !== '') {
                $this->error("Tâche inconnue : {$name} (bundle désactivé ou absent ?)");
            }
            $this->line('Tâches disponibles :');
            foreach ($runner->getJobMeta() as $meta) {
                $this->line(sprintf('  %-24s %s', $meta['name'], $meta['label']));
            }

            return $name === '' ? self::SUCCESS : self::FAILURE;
        }

        $params = [];
        foreach ((array) $this->option('param') as $pair) {
            [$key, $value] = array_pad(explode('=', (string) $pair, 2), 2, '1');
            $params[$key] = $value;
        }

        $started = date('Y-m-d H:i:s');
        try {
            $response = $runner->runLocal($name, Request::create('/cron/run/' . rawurlencode($name), 'GET', $params));
        } catch (\Throwable $e) {
            $this->error(sprintf('[%s] %s : échec — %s', $started, $name, $e->getMessage()));

            return self::FAILURE;
        }

        $this->line(sprintf('[%s] %s : %s', $started, $name, $response->getContent()));

        return $response->getStatusCode() < 400 ? self::SUCCESS : self::FAILURE;
    }
}
