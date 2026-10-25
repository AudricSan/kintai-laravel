<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Planificateur (config/schedule.php) : `* * * * * php artisan schedule:run` dans la crontab suffit.
foreach ((array) config('schedule.jobs', []) as $job => $expression) {
    if (!is_string($expression) || $expression === '') {
        continue;
    }
    Schedule::exec(PHP_BINARY, [base_path('scripts/cron.php'), (string) $job, '--if-available'])
        ->cron($expression)
        ->name('kintai:cron ' . $job)
        // Une tâche encore en cours (grosse sauvegarde, agenda lent) n'est pas relancée ; verrou libéré après 2 h au pire.
        ->withoutOverlapping(120)
        ->runInBackground();
}

// File d'attente « database » : traitée chaque minute, jusqu'à ce qu'elle soit vide.
if (config('queue.default') === 'database') {
    Schedule::command('queue:work', [
        '--stop-when-empty',
        '--max-time=' . (int) config('schedule.queue_worker.max_time', 50),
        '--tries=1',
    ])
        ->everyMinute()
        ->name('queue:work')
        ->withoutOverlapping(10)
        ->runInBackground();
}
