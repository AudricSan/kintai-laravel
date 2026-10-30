<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SystemNotificationService;
use Illuminate\Console\Command;

/**
 * Détecte ce qui mérite l'attention des Owners (mise à jour, migrations en attente, sauvegarde, canaux, quota) et les
 * notifie une fois par changement. Voir SystemNotificationService.
 */
final class SystemNotificationsCommand extends Command
{
    protected $signature = 'kintai:system-notifications';

    protected $description = 'Alertes système aux Owners';

    public function handle(SystemNotificationService $service): int
    {
        $this->line(json_encode(['ok' => true, 'notified' => $service->run(true)]));

        return self::SUCCESS;
    }
}
