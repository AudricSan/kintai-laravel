<?php

declare(strict_types=1);

namespace kintai\Core\Repositories;

use kintai\Domain\Eloquent\WebPushSubscription;

final class DatabaseWebPushSubscriptionRepository implements WebPushSubscriptionRepositoryInterface
{
    public function findByUser(int $userId): array
    {
        return WebPushSubscription::where('user_id', $userId)->orderBy('id')->get()->toArray();
    }

    public function findByUsers(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if ($userIds === []) {
            return [];
        }
        $out = [];
        foreach (WebPushSubscription::whereIn('user_id', $userIds)->orderBy('id')->get()->toArray() as $row) {
            $out[(int) $row['user_id']][] = $row;
        }
        return $out;
    }

    public function findByEndpoint(string $endpoint): ?array
    {
        return WebPushSubscription::where('endpoint_hash', self::hash($endpoint))->first()?->toArray();
    }

    public function save(int $userId, string $endpoint, string $p256dh, string $auth, ?string $userAgent): array
    {
        $record = WebPushSubscription::updateOrCreate(
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

    public function deleteByEndpoint(string $endpoint, ?int $userId = null): int
    {
        $query = WebPushSubscription::where('endpoint_hash', self::hash($endpoint));
        if ($userId !== null) {
            $query->where('user_id', $userId);
        }
        return $query->delete();
    }

    public function deleteById(int $id): void
    {
        WebPushSubscription::where('id', $id)->delete();
    }

    public function touchLastUsed(int $id): void
    {
        WebPushSubscription::where('id', $id)->update(['last_used_at' => date('Y-m-d H:i:s')]);
    }

    public function countAll(): int
    {
        return WebPushSubscription::count();
    }

    public function purgeUnusedSince(string $before): int
    {
        return WebPushSubscription::where(function ($q) use ($before) {
            $q->where('last_used_at', '<', $before)
                ->orWhere(function ($q) use ($before) {
                    $q->whereNull('last_used_at')->where('created_at', '<', $before);
                });
        })->delete();
    }

    public function deleteAllForUser(int $userId): void
    {
        WebPushSubscription::where('user_id', $userId)->delete();
    }

    private static function hash(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
