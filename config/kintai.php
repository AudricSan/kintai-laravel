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
    | Version
    |--------------------------------------------------------------------------
    |
    | Version de Kintai, annoncée par `GET /api/v1/ping`. Constante à mettre à jour à chaque version publiée
    | (composer.json ne porte pas de version).
    |
    */

    'version' => '0.1.0',

    /*
    |--------------------------------------------------------------------------
    | API v1
    |--------------------------------------------------------------------------
    |
    | `version` : numéro de version du contrat de l'API (préfixe d'URL `/api/v1`).
    | `rate_limit` : requêtes par minute et par jeton (par adresse IP sans jeton).
    | `token_max_days` : durée de vie maximale, et par défaut, d'un jeton d'accès (en jours).
    |
    */

    'api' => [
        'version' => '1',
        'rate_limit' => (int) env('KINTAI_API_RATE_LIMIT', 300),
        'token_max_days' => 90,
    ],

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

    /*
    |--------------------------------------------------------------------------
    | Web Push (navigateurs)
    |--------------------------------------------------------------------------
    |
    | Paire de clés VAPID de l'instance, créée par `php artisan kintai:webpush:keys`. Sans clés, le Web Push
    | est désactivé (aucune erreur). `subject` : contact transmis aux services de push (mailto: ou URL https) ;
    | à défaut, l'URL de l'application si elle est en https.
    |
    */

    'webpush' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT'),
    ],

    /*
    |--------------------------------------------------------------------------
    | FCM (application mobile)
    |--------------------------------------------------------------------------
    |
    | Firebase Cloud Messaging, API HTTP v1. `credentials_path` : fichier JSON du compte de service, chemin
    | absolu ou relatif à `storage/`. Tant que `enabled` est faux, rien n'est envoyé.
    |
    */

    'fcm' => [
        'enabled' => filter_var(env('FCM_ENABLED', false), FILTER_VALIDATE_BOOL),
        'project_id' => env('FCM_PROJECT_ID', ''),
        'credentials_path' => env('FCM_CREDENTIALS_PATH', ''),
    ],

];
