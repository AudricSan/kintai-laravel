<?php

namespace App\Console\Commands;

use App\Settings\AppSettings;
use App\Update\UpdateChecker;
use App\Update\UpdateException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Rafraîchit le cache de la dernière release du canal réglé, pour que l'alerte système `core_update_available` (qui ne fait
 * jamais d'appel réseau) la trouve. Planifiée toutes les heures avant `kintai:system:alerts`. Ne plante jamais : une erreur
 * réseau est seulement journalisée.
 */
class RefreshUpdateCheckCommand extends Command
{
    protected $signature = 'kintai:update:refresh';

    protected $description = 'Refresh the cached latest release of the update channel (used by the update alert)';

    public function handle(UpdateChecker $checker, AppSettings $settings): int
    {
        try {
            $release = $checker->check($settings->updateChannel(), UpdateChecker::REFRESH_CACHE_MINUTES);
            $this->components->info($release === null ? 'No release on this channel.' : 'Latest release: '.$release->version.'.');
        } catch (UpdateException $e) {
            Log::warning('Update check failed: '.$e->getMessage());
            $this->components->warn('Update check failed: '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
