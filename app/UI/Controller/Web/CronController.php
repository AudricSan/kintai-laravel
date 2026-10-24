<?php

declare(strict_types=1);

namespace kintai\UI\Controller\Web;

use kintai\Core\Cron\CronRunner;
use kintai\Core\Request;
use kintai\Core\Response;

/**
 * Point d'entrée HTTP des tâches planifiées : `/cron/run/{tâche}`, via CronRunner, pour toutes les tâches du Core
 * (`backup`, `log-purge`, `license-check`, `notification-purge`, `system-notifications`, `calendar-sync`) et celles des bundles actifs (`auto-validate` de daily-report,
 * `photo-retention` de store-photos…). Protégé par un jeton par tâche, stocké (haché) dans la table `cron_tokens`,
 * passé de préférence en en-tête `Authorization: Bearer <jeton>` (un `?token=` finit dans les journaux d'accès du
 * serveur) ; `?token=` reste accepté pour les planificateurs qui ne savent appeler qu'une URL. Jeton créé par
 * `php scripts/create-cron-token.php --user-id=1 --job=backup` ou la page d'administration.
 *
 *    ─── Hébergement mutualisé (OVH…) : tâches planifiées de l'hébergeur ───────
 *     https://votresite.com/cron/run/auto-validate?token=VOTRE_JETON   (toutes les heures)
 *
 *    ─── Serveur avec accès SSH : crontab locale, sans jeton ─────────────────
 *     0 * * * * php /var/www/html/scripts/cron.php auto-validate >> /var/log/kintai-cron.log 2>&1
 */
final class CronController
{
    public function __construct(
        private readonly CronRunner $cronRunner,
    ) {}

    /**
     * GET /cron/run/{job}?token=TOKEN
     *
     * Exécute une tâche enregistrée via CronRunner. Le jeton doit exister dans la table `cron_tokens`, non expiré, et
     * soit non limité à une tâche, soit limité à celle-ci.
     */
    public function run(Request $request): Response
    {
        $jobName = (string) $request->param('job');
        return $this->cronRunner->run($jobName, $request);
    }
}
