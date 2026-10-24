<?php
declare(strict_types=1);

namespace kintai\Core\Repositories;

use kintai\Domain\Eloquent\DevicePushToken;

final class DatabaseDevicePushTokenRepository implements DevicePushTokenRepositoryInterface
{
    public function __construct() {}

    public function findByUser(int $userId): array
    {
        return DevicePushToken::where('user_id', $userId)->get()->toArray();
    }

    public function findByToken(string $token): ?array
    {
        $record = DevicePushToken::where('token', $token)->first();
        return $record ? $record->toArray() : null;
    }

    public function save(array $data): array
    {
        $record = isset($data['token']) ? DevicePushToken::where('token', $data['token'])->first() : null;
        if ($record !== null) {
            $record->fill($data);
            $record->save();
        } else {
            $record = DevicePushToken::create($data);
        }
        return $record->toArray();
    }

    public function deleteByToken(string $token, ?int $userId = null): int
    {
        $query = DevicePushToken::where('token', $token);
        if ($userId !== null) {
            $query->where('user_id', $userId);
        }
        return $query->delete();
    }

    public function touchLastUsed(int $id): void
    {
        DevicePushToken::where('id', $id)->update(['last_used_at' => date('Y-m-d H:i:s')]);
    }
}
