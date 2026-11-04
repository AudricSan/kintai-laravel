<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Fuseau de l'instance
    |--------------------------------------------------------------------------
    |
    | Les horodatages sont stockés en UTC (app.timezone). Ce fuseau sert à saisir
    | et à afficher les heures des shifts.
    |
    */

    'timezone' => env('KINTAI_TIMEZONE', 'UTC'),

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | `retention_days` : jours pendant lesquels une notification lue est conservée
    | avant la purge quotidienne (bornée entre 7 et 3650).
    |
    */

    'notifications' => [
        'retention_days' => (int) env('KINTAI_NOTIFICATIONS_RETENTION_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Journal d'activité
    |--------------------------------------------------------------------------
    |
    | `retention_days` : jours de conservation des entrées avant la purge quotidienne
    | (0 = conservation illimitée).
    |
    */

    'activity' => [
        'retention_days' => (int) env('KINTAI_ACTIVITY_RETENTION_DAYS', 180),
    ],

];
