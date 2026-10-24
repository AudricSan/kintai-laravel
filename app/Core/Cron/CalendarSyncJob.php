<?php

declare(strict_types=1);

namespace kintai\Core\Cron;

use kintai\Core\Repositories\CalendarSyncRepositoryInterface;
use kintai\Core\Request;
use kintai\Core\Response;
use kintai\Core\Services\Calendar\GoogleCalendarSettings;
use kintai\Core\Services\CalendarSyncService;

/**
 * Synchronisation des agendas externes : rejoue la file des shifts modifiés, puis réconcilie périodiquement chaque
 * liaison active (rattrape une écriture oubliée, un congé approuvé, un événement supprimé à la main côté Google).
 *
 * Sans effet quand la fonction n'est pas activée par l'Owner. À planifier toutes les minutes pour que l'agenda suive les
 * modifications en quelques instants ; à défaut, la réconciliation intervient à chaque passage du cron.
 */
final class CalendarSyncJob implements CronJobInterface
{
    public function __construct(
        private readonly CalendarSyncRepositoryInterface $repo,
        private readonly CalendarSyncService $sync,
        private readonly GoogleCalendarSettings $settings,
    ) {}

    public function getName(): string
    {
        return 'calendar-sync';
    }

    public function run(Request $request): Response
    {
        if (!$this->settings->isAvailable()) {
            return Response::json(['ok' => true, 'enabled' => false]);
        }

        $reconciled = 0;
        foreach ($this->repo->activeConnectionsForUsers($this->connectedUserIds()) as $connection) {
            if ($this->sync->reconcileIsDue($connection)) {
                $reconciled += $this->sync->reconcile($connection);
            }
        }

        return Response::json(['ok' => true, 'enabled' => true, 'reconciled' => $reconciled] + $this->sync->processQueue());
    }

    /** @return list<int> */
    private function connectedUserIds(): array
    {
        return $this->repo->activeUserIds();
    }
}
