<?php

declare(strict_types=1);

namespace kintai\Core\Repositories;

use kintai\Domain\Eloquent\NotificationPreference;

final class DatabaseNotificationPreferenceRepository implements NotificationPreferenceRepositoryInterface
{
    public function findByUser(int $userId): array
    {
        return $this->findByUsers([$userId])[$userId] ?? [];
    }

    public function findByUsers(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if ($userIds === []) {
            return [];
        }

        $out = [];
        foreach (NotificationPreference::whereIn('user_id', $userIds)->get(['user_id', 'type', 'channel', 'enabled']) as $row) {
            $out[(int) $row->user_id][(string) $row->type][(string) $row->channel] = (bool) $row->enabled;
        }
        return $out;
    }

    public function set(int $userId, string $type, string $channel, bool $enabled): void
    {
        NotificationPreference::updateOrCreate(
            ['user_id' => $userId, 'type' => $type, 'channel' => $channel],
            ['enabled' => $enabled],
        );
    }

    public function reset(int $userId, string $type, string $channel): void
    {
        NotificationPreference::where('user_id', $userId)->where('type', $type)->where('channel', $channel)->delete();
    }

    public function deleteAllForUser(int $userId): void
    {
        NotificationPreference::where('user_id', $userId)->delete();
    }
}
