<?php

declare(strict_types=1);

namespace kintai\Core\Cron;

use kintai\Core\Repositories\DatabaseAppSettingsRepository;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\AppSettingsService;
use kintai\Core\Services\AuditLogger;
use kintai\Core\Services\BackupService;
use kintai\Core\Services\SystemNotificationService;

final class BackupJob implements CronJobInterface
{
    private readonly AppSettingsService $settings;
    private readonly BackupService $backupService;

    public function __construct(
        private readonly AuditLogger $auditLogger,
        ?AppSettingsService $settings = null,
        ?BackupService $backupService = null,
    ) {
        // Pas de « ??= » sur une propriété promue en lecture seule : elle lèverait une Error si l'argument est null.
        $this->settings      = $settings ?? new AppSettingsService(new DatabaseAppSettingsRepository());
        $this->backupService = $backupService ?? new BackupService();
    }

    public function getName(): string
    {
        return 'backup';
    }

    public function run(Request $request): Response
    {
        if (!$this->settings->backupAutoEnabled()) {
            return Response::json(['ok' => false, 'error' => 'Backup automatique désactivé.']);
        }

        set_time_limit(0);

        $note = trim((string) $request->query('note', ''));
        $note = $note ?: '[auto] Backup quotidien';

        try {
            $result = $this->backupService->create($note);
            $this->backupService->enforceRetention($this->settings->backupMaxKeep());
            // Succès : l'éventuelle alerte « sauvegarde en échec » des Owners n'a plus d'objet.
            $this->settings->setMany([SystemNotificationService::BACKUP_FAILURE_KEY => '']);

            $this->auditLogger->log($request, 'backup.created', 'system', null, [
                'filename' => $result['filename'],
                'size'     => $result['size'],
                'source'   => 'cron',
            ]);

            return Response::json([
                'ok'       => true,
                'filename' => $result['filename'],
                'size'     => $result['size'],
            ]);
        } catch (\RuntimeException $e) {
            // Mémorisé pour SystemNotificationService (notification « backup_failed » aux Owners).
            $this->settings->setMany([SystemNotificationService::BACKUP_FAILURE_KEY => date('Y-m-d H:i:s')]);
            return Response::json([
                'ok'    => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
