<?php

declare(strict_types=1);

use App\Logging\ActivityLogHandler;

/**
 * Journaux de Kintai : trois canaux Monolog, tous écrits dans la table activity_log (page « Journal d'activité »).
 *
 *   Log::info('…')                      canal « business » (canal par défaut) : événements applicatifs et erreurs
 *   Log::channel('audit')->info(…)      canal « audit » : qui a modifié quoi (AuditLogger)
 *   Log::channel('access')->info(…)     canal « access » : une ligne par requête (middleware AccessLog)
 *
 * Clés réservées du contexte, rangées dans leur colonne : action, resource_type, resource_id, user_id, store_id,
 * response_status, duration_ms. Le reste est conservé en JSON dans la colonne « context ».
 */
return [
    'default' => 'business',

    'deprecations' => [
        'channel' => null,
        'trace'   => false,
    ],

    'channels' => [
        'business' => ['driver' => 'monolog', 'handler' => ActivityLogHandler::class, 'with' => ['channel' => 'business'], 'level' => 'debug'],
        'audit'    => ['driver' => 'monolog', 'handler' => ActivityLogHandler::class, 'with' => ['channel' => 'audit'], 'level' => 'debug'],
        'access'   => ['driver' => 'monolog', 'handler' => ActivityLogHandler::class, 'with' => ['channel' => 'access'], 'level' => 'debug'],
    ],
];
