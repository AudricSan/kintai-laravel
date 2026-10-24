<?php

declare(strict_types=1);

namespace kintai\Core\Repositories;

use kintai\Domain\Eloquent\CalendarConnection;
use kintai\Domain\Eloquent\CalendarEventLink;
use kintai\Domain\Eloquent\CalendarSyncQueueItem;

final class DatabaseCalendarSyncRepository implements CalendarSyncRepositoryInterface
{
    public function findConnectionByUser(int $userId, string $provider = 'google'): ?array
    {
        return CalendarConnection::where('user_id', $userId)->where('provider', $provider)->first()?->toArray();
    }

    public function findConnection(int $id): ?array
    {
        return CalendarConnection::find($id)?->toArray();
    }

    public function activeConnectionsForUsers(array $userIds, string $provider = 'google'): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), fn(int $id) => $id > 0)));
        if ($userIds === []) {
            return [];
        }
        return CalendarConnection::whereIn('user_id', $userIds)->where('provider', $provider)->where('status', 'active')
            ->get()->toArray();
    }

    public function saveConnection(int $userId, array $data, string $provider = 'google'): array
    {
        $existing = $this->findConnectionByUser($userId, $provider);
        if ($existing !== null) {
            // Une nouvelle liaison repart de zéro : l'ancien agenda n'est plus celui de Kintai.
            $this->deleteConnection((int) $existing['id']);
        }
        $record = CalendarConnection::create($data + ['user_id' => $userId, 'provider' => $provider, 'status' => 'active']);
        return $record->toArray();
    }

    public function updateConnection(int $id, array $fields): void
    {
        $fields['updated_at'] = date('Y-m-d H:i:s');
        CalendarConnection::where('id', $id)->update($fields);
    }

    public function deleteConnection(int $id): void
    {
        CalendarEventLink::where('connection_id', $id)->delete();
        CalendarSyncQueueItem::where('connection_id', $id)->delete();
        CalendarConnection::where('id', $id)->delete();
    }

    public function deleteAllForUser(int $userId): void
    {
        foreach (CalendarConnection::where('user_id', $userId)->pluck('id') as $id) {
            $this->deleteConnection((int) $id);
        }
    }

    public function countActiveConnections(): int
    {
        return CalendarConnection::where('status', 'active')->count();
    }

    public function activeUserIds(string $provider = 'google'): array
    {
        return array_map('intval', CalendarConnection::where('provider', $provider)->where('status', 'active')->pluck('user_id')->all());
    }

    public function findLink(int $connectionId, string $sourceType, int $sourceId): ?array
    {
        return CalendarEventLink::where('connection_id', $connectionId)->where('source_type', $sourceType)
            ->where('source_id', $sourceId)->first()?->toArray();
    }

    public function linksForConnection(int $connectionId, string $sourceType): array
    {
        return CalendarEventLink::where('connection_id', $connectionId)->where('source_type', $sourceType)->get()->toArray();
    }

    public function saveLink(int $connectionId, string $sourceType, int $sourceId, string $externalEventId, string $hash): void
    {
        CalendarEventLink::updateOrCreate(
            ['connection_id' => $connectionId, 'source_type' => $sourceType, 'source_id' => $sourceId],
            ['external_event_id' => $externalEventId, 'content_hash' => $hash, 'synced_at' => date('Y-m-d H:i:s')],
        );
    }

    public function deleteLink(int $connectionId, string $sourceType, int $sourceId): void
    {
        CalendarEventLink::where('connection_id', $connectionId)->where('source_type', $sourceType)
            ->where('source_id', $sourceId)->delete();
    }

    public function enqueue(int $connectionId, string $sourceType, int $sourceId): void
    {
        CalendarSyncQueueItem::updateOrCreate(
            ['connection_id' => $connectionId, 'source_type' => $sourceType, 'source_id' => $sourceId],
            ['attempts' => 0, 'next_attempt_at' => null, 'last_error' => null],
        );
    }

    public function dueQueue(int $limit): array
    {
        return CalendarSyncQueueItem::where(function ($q) {
            $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', date('Y-m-d H:i:s'));
        })->orderBy('id')->limit(max(1, $limit))->get()->toArray();
    }

    public function removeFromQueue(int $queueId): void
    {
        CalendarSyncQueueItem::where('id', $queueId)->delete();
    }

    public function postponeQueueItem(int $queueId, int $attempts, string $nextAttemptAt, string $error): void
    {
        CalendarSyncQueueItem::where('id', $queueId)->update([
            'attempts' => $attempts, 'next_attempt_at' => $nextAttemptAt, 'last_error' => mb_substr($error, 0, 500),
        ]);
    }
}
