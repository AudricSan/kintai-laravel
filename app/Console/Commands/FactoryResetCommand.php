<?php

namespace App\Console\Commands;

use App\Actions\Backups\FactoryReset;
use App\Backups\BackupException;
use Illuminate\Console\Command;

/**
 * Réinitialisation d'usine depuis le terminal : mêmes effets que l'écran de l'Owner, sans utilisateur. Sans `--force`,
 * la phrase de confirmation doit être recopiée.
 */
class FactoryResetCommand extends Command
{
    protected $signature = 'kintai:factory-reset {--force : Do not ask for the confirmation phrase}';

    protected $description = 'Erase the whole database (after a backup) and go back to the installation wizard';

    public function handle(FactoryReset $reset): int
    {
        if (! $this->option('force')) {
            $this->components->warn('This erases ALL data (accounts, roles, settings, activity log). A backup is made first; .env and APP_KEY are kept.');
            $answer = $this->ask('Type '.FactoryReset::CONFIRMATION.' to confirm');

            if ($answer !== FactoryReset::CONFIRMATION) {
                $this->components->error('Confirmation phrase not matched: nothing was changed.');

                return self::FAILURE;
            }
        }

        try {
            $backup = $reset->run();
        } catch (BackupException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Factory reset done. Backup made first: '.$backup.'. Open /install or run kintai:install.');

        return self::SUCCESS;
    }
}
