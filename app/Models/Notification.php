<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class Notification extends Model
{
    protected $table = 'notifications';
    protected $guarded = [];
    public $timestamps = false;

    public static function findById(int $id): ?array
    {
        $record = static::find($id);
        return $record ? static::fromStorage($record->toArray()) : null;
    }

    public static function findByUser(int $userId, int $limit = 20, int $offset = 0): array
    {
        return static::map(
            static::where('user_id', $userId)
                ->orderBy('id', 'desc')
                ->skip(max(0, $offset))
                ->take($limit)
                ->get()
                ->toArray()
        );
    }

    public static function findUnreadByUser(int $userId, int $limit = 10): array
    {
        return static::map(
            static::where('user_id', $userId)
                ->whereNull('read_at')
                ->orderBy('id', 'desc')
                ->take($limit)
                ->get()
                ->toArray()
        );
    }

    public static function countByUser(int $userId): int
    {
        return static::where('user_id', $userId)->count();
    }

    public static function findAfterId(int $userId, int $afterId, int $limit = 50): array
    {
        return static::map(
            static::where('user_id', $userId)
                ->where('id', '>', $afterId)
                ->orderBy('id')
                ->take($limit)
                ->get()
                ->toArray()
        );
    }

    public static function countUnread(int $userId): int
    {
        return static::where('user_id', $userId)
            ->whereNull('read_at')
            ->count();
    }

    public static function put(array $data): array
    {
        $payload = static::toStorage($data);
        if (!empty($payload['id'])) {
            $record = static::findOrFail((int) $payload['id']);
            $record->fill($payload);
            $record->save();
        } else {
            $record = static::create($payload);
        }
        return static::fromStorage($record->toArray());
    }

    public static function markRead(int $id, int $userId): void
    {
        static::where('id', $id)
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => date('Y-m-d H:i:s')]);
    }

    public static function markReadByReference(int $userId, string $referenceType, array $referenceIds): void
    {
        if ($referenceIds === []) {
            return;
        }
        static::where('user_id', $userId)
            ->where('reference_type', $referenceType)
            ->whereIn('reference_id', array_map('intval', $referenceIds))
            ->whereNull('read_at')
            ->update(['read_at' => date('Y-m-d H:i:s')]);
    }

    public static function markAllRead(int $userId): void
    {
        static::where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => date('Y-m-d H:i:s')]);
    }

    public static function remove(int $id): int
    {
        $record = static::find($id);
        return $record ? ($record->delete() ? 1 : 0) : 0;
    }

    public static function purgeReadBefore(string $before, int $batchSize = 1000): int
    {
        $batchSize = max(1, $batchSize);
        $deleted   = 0;
        do {
            $ids = static::whereNotNull('read_at')->where('read_at', '<', $before)
                ->orderBy('id')->limit($batchSize)->pluck('id')->all();
            if ($ids !== []) {
                $deleted += static::whereIn('id', $ids)->delete();
            }
        } while (count($ids) === $batchSize);

        return $deleted;
    }

    public static function deleteAllForUser(int $userId): void
    {
        static::where('user_id', $userId)->delete();
    }

    /** @param array[] $rows */
    private static function map(array $rows): array
    {
        return array_map(fn(array $row) => static::fromStorage($row), $rows);
    }

    private static function toStorage(array $data): array
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

    private static function fromStorage(array $row): array
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
