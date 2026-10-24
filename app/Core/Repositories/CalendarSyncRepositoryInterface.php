<?php

declare(strict_types=1);

namespace kintai\Core\Repositories;

/**
 * Liaisons d'agenda externe, correspondances shift ↔ événement et file de synchronisation.
 * Connexion : id, user_id, provider, account_email, refresh_token (chiffré), calendar_id, calendar_name, status,
 * last_sync_at, last_error, options (JSON).
 */
interface CalendarSyncRepositoryInterface
{
    public function findConnectionByUser(int $userId, string $provider = 'google'): ?array;

    public function findConnection(int $id): ?array;

    /**
     * @param list<int> $userIds
     * @return array[] liaisons actives de ces utilisateurs
     */
    public function activeConnectionsForUsers(array $userIds, string $provider = 'google'): array;

    /**
     * Enregistre la liaison de l'utilisateur (remplace la précédente, correspondances et file comprises).
     *
     * @param array<string, mixed> $data
     */
    public function saveConnection(int $userId, array $data, string $provider = 'google'): array;

    /** @param array<string, mixed> $fields */
    public function updateConnection(int $id, array $fields): void;

    /** Supprime la liaison, ses correspondances et sa file. */
    public function deleteConnection(int $id): void;

    /** Supprime les liaisons d'un utilisateur (suppression du compte, SQLite n'applique pas les cascades). */
    public function deleteAllForUser(int $userId): void;

    public function countActiveConnections(): int;

    /** @return list<int> utilisateurs ayant une liaison active */
    public function activeUserIds(string $provider = 'google'): array;

    public function findLink(int $connectionId, string $sourceType, int $sourceId): ?array;

    /** @return array[] */
    public function linksForConnection(int $connectionId, string $sourceType): array;

    public function saveLink(int $connectionId, string $sourceType, int $sourceId, string $externalEventId, string $hash): void;

    public function deleteLink(int $connectionId, string $sourceType, int $sourceId): void;

    /** Met une source en file : une seule ligne par liaison et source, une demande déjà en attente est avancée. */
    public function enqueue(int $connectionId, string $sourceType, int $sourceId): void;

    /** @return array[] éléments dus (next_attempt_at nul ou passé), les plus anciens d'abord */
    public function dueQueue(int $limit): array;

    public function removeFromQueue(int $queueId): void;

    public function postponeQueueItem(int $queueId, int $attempts, string $nextAttemptAt, string $error): void;
}
