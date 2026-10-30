<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Repositories\CalendarSyncRepositoryInterface;
use App\Services\Calendar\GoogleCalendarSettings;
use App\Services\CalendarSyncService;
use Illuminate\Console\Command;

/**
 * Synchronisation des agendas externes : rejoue la file des shifts modifiés, puis réconcilie périodiquement chaque
 * liaison active (rattrape une écriture oubliée, un congé approuvé, un événement supprimé à la main côté Google).
 *
 * Sans effet quand la fonction n'est pas activée par l'Owner. Planifiée chaque minute pour que l'agenda suive les
 * modifications en quelques instants.
 */
final class CalendarSyncCommand extends Command
{
    protected $signature = 'kintai:calendar-sync';

    protected $description = 'Synchronisation des agendas externes';

    public function handle(CalendarSyncRepositoryInterface $repo, CalendarSyncService $sync, GoogleCalendarSettings $settings): int
    {
        if (!$settings->isAvailable()) {
            $this->line(json_encode(['ok' => true, 'enabled' => false]));

            return self::SUCCESS;
        }

        $reconciled = 0;
        foreach ($repo->activeConnectionsForUsers($repo->activeUserIds()) as $connection) {
            if ($sync->reconcileIsDue($connection)) {
                $reconciled += $sync->reconcile($connection);
            }
        }

        $this->line(json_encode(['ok' => true, 'enabled' => true, 'reconciled' => $reconciled] + $sync->processQueue()));

        return self::SUCCESS;
    }
}
