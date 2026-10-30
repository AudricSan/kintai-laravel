<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class WebPushSubscription extends Model
{
    protected $table = 'web_push_subscriptions';
    protected $guarded = [];
    public $timestamps = false;

    public static function findByUser(int $userId): array
    {
        return static::where('user_id', $userId)->orderBy('id')->get()->toArray();
    }

    public static function findByUsers(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if ($userIds === []) {
            return [];
        }
        $out = [];
        foreach (static::whereIn('user_id', $userIds)->orderBy('id')->get()->toArray() as $row) {
            $out[(int) $row['user_id']][] = $row;
        }
        return $out;
    }

    public static function findByEndpoint(string $endpoint): ?array
    {
        return static::where('endpoint_hash', self::hash($endpoint))->first()?->toArray();
    }

    public static function put(int $userId, string $endpoint, string $p256dh, string $auth, ?string $userAgent): array
    {
        $record = static::updateOrCreate(
            ['endpoint_hash' => self::hash($endpoint)],
            [
                'user_id'      => $userId,
                'endpoint'     => $endpoint,
                'p256dh'       => $p256dh,
                'auth'         => $auth,
                'user_agent'   => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
                'last_used_at' => date('Y-m-d H:i:s'),
            ],
        );
        return $record->toArray();
    }

    public static function deleteByEndpoint(string $endpoint, ?int $userId = null): int
    {
        $query = static::where('endpoint_hash', self::hash($endpoint));
        if ($userId !== null) {
            $query->where('user_id', $userId);
        }
        return $query->delete();
    }

    public static function deleteById(int $id): void
    {
        static::where('id', $id)->delete();
    }

    public static function touchLastUsed(int $id): void
    {
        static::where('id', $id)->update(['last_used_at' => date('Y-m-d H:i:s')]);
    }

    public static function countAll(): int
    {
        return static::count();
    }

    public static function purgeUnusedSince(string $before): int
    {
        return static::where(function ($q) use ($before) {
            $q->where('last_used_at', '<', $before)
                ->orWhere(function ($q) use ($before) {
                    $q->whereNull('last_used_at')->where('created_at', '<', $before);
                });
        })->delete();
    }

    public static function deleteAllForUser(int $userId): void
    {
        static::where('user_id', $userId)->delete();
    }

    private static function hash(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
