<?php

declare(strict_types=1);

/**
 * Planificateur : tâches lancées par une seule ligne de crontab, chaque minute.
 *
 *   * * * * * cd /chemin/vers/kintai && php artisan schedule:run >> /dev/null 2>&1
 *
 * Chaque tâche tourne comme `php scripts/cron.php <tâche>` (même code, même journal), dans son propre processus. Une
 * tâche d'un bundle absent ou désactivé est sautée sans erreur. Les URL /cron/run/{tâche} avec jeton restent
 * disponibles pour les hébergements qui ne savent appeler qu'une adresse.
 *
 * Expression cron par tâche ; null retire une tâche du planificateur. Liste affichée par `php artisan schedule:list`.
 */
return [
    'jobs' => [
        // Core. backup ne fait rien tant que la sauvegarde automatique n'est pas activée par l'Owner.
        'backup'                  => '0 3 * * *',
        'log-purge'               => '30 3 * * *',
        'notification-purge'      => '45 3 * * *',
        'license-check'           => '0 4 * * *',
        'system-notifications'    => '0 * * * *',
        // Sans effet tant que la synchronisation des agendas n'est pas activée.
        'calendar-sync'           => '* * * * *',

        // Bundles officiels.
        'auto-validate'           => '0 * * * *',
        'planning-check-escalate' => '*/15 * * * *',
        'resignation-deactivate'  => '15 0 * * *',
        'photo-retention'         => '0 2 * * *',
    ],

    // File d'attente « database » (config/queue.php) : vidée chaque minute, sans processus permanent à surveiller.
    'queue_worker' => [
        'max_time' => 50,
    ],
];
