<?php

declare(strict_types=1);

/**
 * Files d'attente : envoi des canaux externes des notifications (push mobile, web push, e-mail), voir
 * App\Jobs\DeliverNotification.
 *
 * QUEUE_CONNECTION :
 *   deferred  (défaut) Envoi juste après la réponse HTTP, dans le même processus : rien à installer ni à planifier,
 *             la page n'attend plus le serveur SMTP. Une panne pendant l'envoi n'est pas rejouée (comme avant).
 *   database  Tâches rangées dans la table « jobs », traitées par `php artisan queue:work`, ou chaque minute par le
 *             planificateur (`* * * * * php artisan schedule:run`, voir routes/console.php). Pour une instance qui
 *             envoie beaucoup, ou dont le serveur SMTP est lent.
 *   sync      Envoi pendant la requête, comme avant la file d'attente.
 */
return [
    'default' => env('QUEUE_CONNECTION', 'deferred'),

    'connections' => [
        'sync' => [
            'driver' => 'sync',
        ],

        'deferred' => [
            'driver' => 'deferred',
        ],

        'database' => [
            'driver'       => 'database',
            'connection'   => null,
            'table'        => 'jobs',
            'queue'        => 'default',
            'retry_after'  => (int) env('DB_QUEUE_RETRY_AFTER', 90),
            'after_commit' => true,
        ],
    ],

    'batching' => [
        'database' => null,
        'table'    => 'job_batches',
    ],

    // Tâches en échec (file « database ») : table failed_jobs, à relancer avec `php artisan queue:retry all`.
    'failed' => [
        'driver'   => 'database-uuids',
        'database' => null,
        'table'    => 'failed_jobs',
    ],
];
