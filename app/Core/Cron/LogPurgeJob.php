<?php

declare(strict_types=1);

namespace kintai\Core\Cron;

use kintai\Core\Repositories\LogRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\AppSettingsService;
use kintai\Core\Services\AuditLogger;

final class LogPurgeJob implements CronJobInterface
{
    public function __construct(
        private readonly LogRepositoryInterface $logs,
        private readonly AppSettingsService $settings,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function getName(): string
    {
        return 'log-purge';
    }

    public function run(Request $request): Response
    {
        $days = $this->settings->logRetentionDays();
        if ($days <= 0) {
            return Response::json(['ok' => false, 'error' => 'Rétention illimitée (log_retention_days = 0), purge désactivée.']);
        }

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $deleted = $this->logs->purgeOlderThan($cutoff);

        $this->auditLogger->log($request, 'log.purged', 'system', null, [
            'retention_days' => $days,
            'cutoff'         => $cutoff,
            'deleted'        => $deleted,
        ]);

        return Response::json([
            'ok'      => true,
            'cutoff'  => $cutoff,
            'deleted' => $deleted,
        ]);
    }
}
