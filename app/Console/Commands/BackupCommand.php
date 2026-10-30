<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AppSettingsService;
use App\Services\AuditLogger;
use App\Services\BackupService;
use App\Services\SystemNotificationService;
use Illuminate\Console\Command;

/**
 * Sauvegarde automatique (planifiée chaque nuit, routes/console.php). Sans effet tant que l'Owner ne l'a pas activée.
 * Une sauvegarde en échec est mémorisée pour que SystemNotificationService en prévienne les Owners.
 */
final class BackupCommand extends Command
{
    protected $signature = 'kintai:backup {--note= : Note de la sauvegarde}';

    protected $description = 'Sauvegarde de la base de données';

    public function handle(AppSettingsService $settings, BackupService $backups, AuditLogger $audit): int
    {
        if (!$settings->backupAutoEnabled()) {
            $this->line(json_encode(['ok' => false, 'error' => 'Backup automatique désactivé.']));

            return self::SUCCESS;
        }

        set_time_limit(0);
        $note = trim((string) $this->option('note')) ?: '[auto] Backup quotidien';

        try {
            $result = $backups->create($note);
            $backups->enforceRetention($settings->backupMaxKeep());
            // Succès : l'éventuelle alerte « sauvegarde en échec » des Owners n'a plus d'objet.
            $settings->setMany([SystemNotificationService::BACKUP_FAILURE_KEY => '']);
            $audit->log(request(), 'backup.created', 'system', null, [
                'filename' => $result['filename'],
                'size'     => $result['size'],
                'source'   => 'cron',
            ]);
            $this->line(json_encode(['ok' => true, 'filename' => $result['filename'], 'size' => $result['size']]));

            return self::SUCCESS;
        } catch (\RuntimeException $e) {
            $settings->setMany([SystemNotificationService::BACKUP_FAILURE_KEY => date('Y-m-d H:i:s')]);
            $this->line(json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE));

            return self::FAILURE;
        }
    }
}
