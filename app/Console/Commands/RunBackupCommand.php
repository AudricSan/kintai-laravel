<?php

namespace App\Console\Commands;

use App\Backups\BackupException;
use App\Backups\BackupManager;
use App\Settings\AppSettings;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sauvegarde automatique quotidienne, puis rotation (`backup.max_keep`). Planifiée seulement si elle est activée.
 * L'état est exposé par les réglages `backup.last_success_at` et `backup.last_failure` (lus plus tard par les alertes système).
 */
class RunBackupCommand extends Command
{
    protected $signature = 'kintai:backup:run {--force : Run even when the automatic backup is disabled}';

    protected $description = 'Create an automatic backup and delete the oldest ones beyond the number to keep';

    public function handle(BackupManager $manager, AppSettings $settings): int
    {
        if (! $settings->backupAutoEnabled() && ! $this->option('force')) {
            $this->components->info('Automatic backups are disabled: nothing to do.');

            return self::SUCCESS;
        }

        try {
            $backup = $manager->create(null, BackupManager::TRIGGER_SCHEDULED);
            $deleted = $manager->prune($settings->backupMaxKeep());

            $settings->setMany(['backup.last_success_at' => now()->toIso8601String(), 'backup.last_failure' => '']);
        } catch (Throwable $e) {
            report($e);

            // Un BackupException porte un message sûr ; toute autre erreur reste dans les journaux techniques.
            $message = $e instanceof BackupException
                ? $e->getMessage()
                : __('The backup could not be created. See the application log for details.');

            try {
                $settings->set('backup.last_failure', now()->toDateTimeString().' - '.$message);
            } catch (Throwable $inner) {
                report($inner);
            }

            $this->components->error($message);

            return self::FAILURE;
        }

        $this->components->info("Backup {$backup->id} created, {$deleted} old backup(s) deleted.");

        return self::SUCCESS;
    }
}
