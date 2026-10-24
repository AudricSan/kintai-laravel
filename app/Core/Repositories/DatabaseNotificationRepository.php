<?php
declare(strict_types=1);

namespace kintai\Core\Repositories;

use kintai\Domain\Eloquent\Notification as EloquentNotification;

/**
 * Les colonnes réelles de `notifications` correspondent au modèle de lecture (voir
 * NotificationRepositoryInterface), à deux traductions près : params est stocké en JSON et décodé en tableau, et
 * is_read n'existe pas en base (dérivé de read_at).
 */
final class DatabaseNotificationRepository implements NotificationRepositoryInterface
{
    public function __construct() {}

    public function findById(int $id): ?array
    {
        $record = EloquentNotification::find($id);
        return $record ? $this->fromStorage($record->toArray()) : null;
    }

    public function findByUser(int $userId, int $limit = 20, int $offset = 0): array
    {
        return $this->map(
            EloquentNotification::where('user_id', $userId)
                ->orderBy('id', 'desc')
                ->skip(max(0, $offset))
                ->take($limit)
                ->get()
                ->toArray()
        );
    }

    public function findUnreadByUser(int $userId, int $limit = 10): array
    {
        return $this->map(
            EloquentNotification::where('user_id', $userId)
                ->whereNull('read_at')
                ->orderBy('id', 'desc')
                ->take($limit)
                ->get()
                ->toArray()
        );
    }

    public function countByUser(int $userId): int
    {
        return EloquentNotification::where('user_id', $userId)->count();
    }

    public function findAfterId(int $userId, int $afterId, int $limit = 50): array
    {
        return $this->map(
            EloquentNotification::where('user_id', $userId)
                ->where('id', '>', $afterId)
                ->orderBy('id')
                ->take($limit)
                ->get()
                ->toArray()
        );
    }

    public function countUnread(int $userId): int
    {
        return EloquentNotification::where('user_id', $userId)
            ->whereNull('read_at')
            ->count();
    }

    public function save(array $data): array
    {
        $payload = $this->toStorage($data);
        if (!empty($payload['id'])) {
            $record = EloquentNotification::findOrFail((int) $payload['id']);
            $record->fill($payload);
            $record->save();
        } else {
            $record = EloquentNotification::create($payload);
        }
        return $this->fromStorage($record->toArray());
    }

    public function markRead(int $id, int $userId): void
    {
        EloquentNotification::where('id', $id)
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => date('Y-m-d H:i:s')]);
    }

    public function markReadByReference(int $userId, string $referenceType, array $referenceIds): void
    {
        if ($referenceIds === []) {
            return;
        }
        EloquentNotification::where('user_id', $userId)
            ->where('reference_type', $referenceType)
            ->whereIn('reference_id', array_map('intval', $referenceIds))
            ->whereNull('read_at')
            ->update(['read_at' => date('Y-m-d H:i:s')]);
    }

    public function markAllRead(int $userId): void
    {
        EloquentNotification::where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => date('Y-m-d H:i:s')]);
    }

    public function delete(int $id): int
    {
        $record = EloquentNotification::find($id);
        return $record ? ($record->delete() ? 1 : 0) : 0;
    }

    public function purgeReadBefore(string $before, int $batchSize = 1000): int
    {
        $batchSize = max(1, $batchSize);
        $deleted   = 0;
        do {
            $ids = EloquentNotification::whereNotNull('read_at')->where('read_at', '<', $before)
                ->orderBy('id')->limit($batchSize)->pluck('id')->all();
            if ($ids !== []) {
                $deleted += EloquentNotification::whereIn('id', $ids)->delete();
            }
        } while (count($ids) === $batchSize);

        return $deleted;
    }

    public function deleteAllForUser(int $userId): void
    {
        EloquentNotification::where('user_id', $userId)->delete();
    }

    /** @param array[] $rows */
    private function map(array $rows): array
    {
        return array_map(fn(array $row) => $this->fromStorage($row), $rows);
    }

    private function toStorage(array $data): array
    {
        $storage = $data;
        if (array_key_exists('params', $storage)) {
            $storage['params'] = is_array($storage['params']) && $storage['params'] !== []
                ? json_encode($storage['params'], JSON_UNESCAPED_UNICODE)
                : null;
        }
        // is_read n'est jamais stocké : uniquement dérivé de read_at à la lecture. Une valeur explicite à la
        // création reste sans effet, read_at garde sa valeur par défaut NULL.
        unset($storage['is_read']);
        return $storage;
    }

    private function fromStorage(array $row): array
    {
        $params = json_decode((string) ($row['params'] ?? ''), true);

        return array_merge($row, [
            'body_key'       => $row['body_key'] ?? null,
            'params'         => is_array($params) ? $params : [],
            'body'           => (string) ($row['body'] ?? ''),
            'link'           => $row['link'] ?? null,
            'reference_type' => $row['reference_type'] ?? null,
            'reference_id'   => isset($row['reference_id']) ? (int) $row['reference_id'] : null,
            'is_read'        => ($row['read_at'] ?? null) !== null ? 1 : 0,
        ]);
    }
}
