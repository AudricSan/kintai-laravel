<?php

declare(strict_types=1);

namespace kintai\Core\Cron;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use kintai\Core\Services\SystemNotificationService;

/**
 * Détecte ce qui mérite l'attention des Owners (mise à jour, migrations en attente, sauvegarde, canaux, quota) et les
 * notifie une fois par changement. Voir SystemNotificationService.
 */
final class SystemNotificationsJob implements CronJobInterface
{
    public function __construct(private readonly SystemNotificationService $service) {}

    public function getName(): string
    {
        return 'system-notifications';
    }

    public function run(Request $request): Response
    {
        return response()->json(['ok' => true, 'notified' => $this->service->run(true)]);
    }
}
