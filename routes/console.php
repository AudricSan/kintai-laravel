<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Planificateur de Laravel : `* * * * * php artisan schedule:run` dans la crontab suffit (`php artisan schedule:list`
// affiche les tâches). Les bundles planifient les leurs dans register() (Schedule::command via callAfterResolving).
Schedule::command('kintai:backup')->dailyAt('03:00')->withoutOverlapping(120)->runInBackground();
Schedule::command('kintai:log-purge')->dailyAt('03:30')->withoutOverlapping(120)->runInBackground();
Schedule::command('kintai:notification-purge')->dailyAt('03:45')->withoutOverlapping(120)->runInBackground();
Schedule::command('kintai:license-check')->dailyAt('04:00')->withoutOverlapping(120)->runInBackground();
Schedule::command('kintai:system-notifications')->hourly()->withoutOverlapping(120)->runInBackground();
// Sans effet tant que la synchronisation des agendas n'est pas activée par l'Owner.
Schedule::command('kintai:calendar-sync')->everyMinute()->withoutOverlapping(120)->runInBackground();

// File d'attente « database » : traitée chaque minute, jusqu'à ce qu'elle soit vide.
if (config('queue.default') === 'database') {
    Schedule::command('queue:work', [
        '--stop-when-empty',
        '--max-time=' . (int) config('queue.worker_max_time', 50),
        '--tries=1',
    ])
        ->everyMinute()
        ->name('queue:work')
        ->withoutOverlapping(10)
        ->runInBackground();
}