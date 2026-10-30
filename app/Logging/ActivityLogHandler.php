<?php

declare(strict_types=1);

namespace App\Logging;

use App\Models\ActivityEntry;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Gestionnaire Monolog du journal d'activité : une ligne de la table activity_log par entrée, avec le contexte de la
 * requête en cours (adresse, navigateur, URL, session, utilisateur). Le canal Monolog (business, audit, access) devient
 * la colonne « channel ». Écrire dans le journal ne doit jamais faire échouer l'action qui l'a déclenché.
 */
final class ActivityLogHandler extends AbstractProcessingHandler
{
    /** Clés du contexte rangées dans leur propre colonne. */
    private const COLUMNS = ['action', 'resource_type', 'resource_id', 'user_id', 'store_id', 'response_status', 'duration_ms'];

    /** @param string $channel Canal Kintai (business, audit, access) : la colonne « channel » de l'entrée */
    public function __construct(private readonly string $channel = 'business')
    {
        parent::__construct(Level::Debug);
    }

    protected function write(LogRecord $record): void
    {
        $context = $record->context;
        $columns = [];
        foreach (self::COLUMNS as $key) {
            $columns[$key] = $context[$key] ?? null;
            unset($context[$key]);
        }

        // Une exception passée en contexte n'a pas de représentation JSON utile : son message suffit.
        foreach ($context as $key => $value) {
            if ($value instanceof \Throwable) {
                $context[$key] = $value->getMessage();
            }
        }

        $user = $this->authenticatedUser();

        try {
            ActivityEntry::create([
                'level'           => strtolower($record->level->getName()),
                'channel'         => $this->channel,
                'message'         => $record->message,
                'action'          => $columns['action'],
                'resource_type'   => $columns['resource_type'],
                'resource_id'     => $columns['resource_id'],
                'context'         => $context !== [] ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : null,
                'user_id'         => $columns['user_id'] ?? ($user['id'] ?? null),
                'store_id'        => $columns['store_id'] ?? ($user['store_id'] ?? null),
                // Adresse réelle de la connexion : X-Forwarded-For et Client-IP viennent du client lui-même.
                'ip_address'      => ($ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''))) !== '' ? $ip : null,
                'user_agent'      => isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 500) : null,
                'request_method'  => $_SERVER['REQUEST_METHOD'] ?? null,
                'request_uri'     => $_SERVER['REQUEST_URI'] ?? null,
                'response_status' => $columns['response_status'],
                'duration_ms'     => $columns['duration_ms'],
                'session_id'      => $this->sessionId(),
                'created_at'      => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Table absente : instance pas encore installée ou migrée, rien à signaler.
            if (str_contains($e->getMessage(), 'no such table') || str_contains($e->getMessage(), "doesn't exist")) {
                return;
            }
            error_log('[Kintai][Log] Échec écriture activity_log: ' . $e->getMessage());
        }
    }

    /**
     * Utilisateur à l'origine de l'entrée : seulement l'attribut « auth_user » de la requête, posé par les middlewares
     * d'authentification. Jamais $_REQUEST : « ?auth_user[id]=1 » dans une URL attribuait l'entrée à n'importe qui.
     *
     * @return array{id?: int|null, store_id?: int|null}
     */
    private function authenticatedUser(): array
    {
        try {
            $user = app('request')->attributes->get('auth_user');
        } catch (\Throwable) {
            return [];
        }

        return is_array($user)
            ? ['id' => isset($user['id']) ? (int) $user['id'] : null, 'store_id' => isset($user['store_id']) ? (int) $user['store_id'] : null]
            : [];
    }

    private function sessionId(): ?string
    {
        try {
            return session()->getId() ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
