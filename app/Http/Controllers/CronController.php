<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Models\CronToken;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\HttpFoundation\Response;

/**
 * Point d'entrée HTTP du planificateur, pour les hébergements qui ne savent appeler qu'une adresse : `/cron/run/{tâche}`
 * lance la commande Artisan `kintai:{tâche}`, à condition que le planificateur de Laravel la planifie (backup, log-purge,
 * license-check, notification-purge, system-notifications, calendar-sync, et celles des bundles actifs : auto-validate,
 * photo-retention…). Protégé par un jeton par tâche, stocké (haché) dans la table `cron_tokens`, passé de préférence
 * en en-tête `Authorization: Bearer <jeton>` (un `?token=` finit dans les journaux d'accès du serveur). Jeton créé par
 * `php artisan kintai:cron-token --user-id=1 --job=backup` ou la page d'administration.
 *
 * Les paramètres de l'adresse (`?date=2026-10-13&all=1`) deviennent les options de la commande, si elle les déclare.
 *
 *    ─── Hébergement mutualisé (OVH…) : tâches planifiées de l'hébergeur ───────
 *     https://votresite.com/cron/run/auto-validate?token=VOTRE_JETON   (toutes les heures)
 *
 *    ─── Serveur avec accès SSH : une ligne de crontab pour tout le planificateur ──
 *     * * * * * cd /var/www/html && php artisan schedule:run >> /dev/null 2>&1
 */
final class CronController
{
    public function run(Request $request): Response
    {
        $job = (string) $request->route('job');

        // Le jeton d'abord : une tâche inconnue ne se distingue pas d'un refus pour qui n'en a pas.
        $record = $this->authorize($request, $job);

        $command = 'kintai:' . $job;
        if (!$this->isScheduled($command)) {
            throw new NotFoundException(__('error_cron_unknown_job', ['job' => $job]));
        }

        try {
            $record->update(['last_used_at' => date('Y-m-d H:i:s')]);
        } catch (\Throwable) {
            // Le jeton a servi : un horodatage non écrit ne bloque pas la tâche.
        }

        $definition = Artisan::all()[$command]->getDefinition();
        $options    = [];
        foreach ($request->query() as $name => $value) {
            if ($name !== 'token' && is_string($name) && $definition->hasOption($name)) {
                $options['--' . $name] = $definition->getOption($name)->acceptValue() ? $value : true;
            }
        }

        $code   = Artisan::call($command, $options);
        $output = trim(Artisan::output());
        $body   = json_decode($output, true);

        return response()->json(is_array($body) ? $body : ['ok' => $code === 0, 'output' => $output], $code === 0 ? 200 : 500);
    }

    /** Jeton valide, non expiré, pour cette tâche : renvoie sa ligne, ou refuse (403). */
    private function authorize(Request $request, string $job): CronToken
    {
        $raw = (string) $request->query('token', '');
        if ($raw === '' && preg_match('/^Bearer\s+(.+)$/i', (string) $request->header('Authorization'), $m) === 1) {
            $raw = $m[1];
        }
        if ($raw === '') {
            throw new ForbiddenException(__('error_cron_token_missing'));
        }

        $record = CronToken::where('token', hash_token($raw))->first();
        if ($record === null) {
            throw new ForbiddenException(__('error_cron_token_invalid'));
        }
        if (!empty($record->expires_at) && (string) $record->expires_at < date('Y-m-d H:i:s')) {
            throw new ForbiddenException(__('error_cron_token_expired'));
        }
        if ((string) $record->job_name !== '' && $record->job_name !== $job) {
            throw new ForbiddenException(__('error_cron_token_not_allowed'));
        }

        return $record;
    }

    /** La commande figure au planificateur : seul ce que Kintai planifie peut être lancé par adresse. */
    private function isScheduled(string $command): bool
    {
        Artisan::all(); // charge les commandes et routes/console.php, donc les tâches planifiées
        foreach (app(Schedule::class)->events() as $event) {
            if (preg_match('/(?:^|[\s\'"])' . preg_quote($command, '/') . '(?:[\s\'"]|$)/', (string) $event->command) === 1) {
                return true;
            }
        }

        return false;
    }
}
