<?php

namespace Kintai\Bundles\GoogleCalendar\Console;

use Illuminate\Console\Command;
use Kintai\Bundles\GoogleCalendar\CalendarSync;
use Kintai\Bundles\GoogleCalendar\GoogleCalendarServiceProvider;
use Kintai\Bundles\GoogleCalendar\GoogleCalendarSettings;
use Kintai\Bundles\GoogleCalendar\Models\CalendarConnection;
use Kintai\Contracts\Bundle\BundleActivation;

/**
 * Synchronisation des agendas Google : rejoue la file des shifts modifiés, puis réconcilie périodiquement chaque liaison
 * active (rattrape une écriture oubliée, un congé approuvé, un événement supprimé à la main côté Google).
 *
 * Planifiée chaque minute par le bundle (planificateur de Laravel, ou `/cron/run`) ; sans effet tant que l'Owner n'a pas
 * activé la fonction et saisi les identifiants du projet Google.
 */
class SyncCommand extends Command
{
    protected $signature = 'google-calendar:sync';

    protected $description = 'Sync the queued shift changes and the approved time off to the linked Google calendars';

    public function handle(BundleActivation $bundles, GoogleCalendarSettings $settings, CalendarSync $sync): int
    {
        // La commande reste déclarée bundle désactivé (comme celles des autres bundles) mais ne fait alors rien.
        if (! $bundles->isEnabled(GoogleCalendarServiceProvider::SLUG) || ! $settings->isAvailable()) {
            $this->line((string) json_encode(['ok' => true, 'enabled' => false]));

            return self::SUCCESS;
        }

        $reconciled = 0;

        foreach (CalendarConnection::query()->active()->get() as $connection) {
            if ($sync->reconcileIsDue($connection)) {
                $reconciled += $sync->reconcile($connection);
            }
        }

        $this->line((string) json_encode(['ok' => true, 'enabled' => true, 'reconciled' => $reconciled] + $sync->processQueue()));

        return self::SUCCESS;
    }
}
