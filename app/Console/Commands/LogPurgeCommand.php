<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Repositories\LogRepositoryInterface;
use App\Services\AppSettingsService;
use App\Services\AuditLogger;
use Illuminate\Console\Command;

/** Purge du journal d'activité au-delà de la rétention réglée par l'Owner (0 jour = rétention illimitée, rien n'est purgé). */
final class LogPurgeCommand extends Command
{
    protected $signature = 'kintai:log-purge';

    protected $description = 'Purge du journal d\'activité';

    public function handle(LogRepositoryInterface $logs, AppSettingsService $settings, AuditLogger $audit): int
    {
        $days = $settings->logRetentionDays();
        if ($days <= 0) {
            $this->line(json_encode(['ok' => false, 'error' => 'Rétention illimitée (log_retention_days = 0), purge désactivée.'], JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $cutoff  = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $deleted = $logs->purgeOlderThan($cutoff);

        $audit->log(request(), 'log.purged', 'system', null, ['retention_days' => $days, 'cutoff' => $cutoff, 'deleted' => $deleted]);
        $this->line(json_encode(['ok' => true, 'cutoff' => $cutoff, 'deleted' => $deleted]));

        return self::SUCCESS;
    }
}
