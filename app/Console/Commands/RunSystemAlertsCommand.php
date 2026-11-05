<?php

namespace App\Console\Commands;

use App\System\SystemAlerts;
use Illuminate\Console\Command;

/**
 * Passe des alertes système (migrations en attente, sauvegardes, canaux de notification) : prévient les Owners actifs
 * quand l'état d'un problème change. Planifiée toutes les heures ; un Owner qui charge une page déclenche aussi une passe
 * (au plus une par heure) si le planificateur ne tourne pas.
 */
class RunSystemAlertsCommand extends Command
{
    protected $signature = 'kintai:system:alerts';

    protected $description = 'Detect system problems and notify the Owners when their state changes';

    public function handle(SystemAlerts $alerts): int
    {
        $sent = $alerts->run();

        $this->components->info($sent === [] ? 'No new system alert.' : 'System alerts sent: '.implode(', ', $sent).'.');

        return self::SUCCESS;
    }
}
