<?php
declare(strict_types=1);

namespace kintai\Core\Repositories;

use kintai\Domain\Eloquent\IcalToken as EloquentIcalToken;

final class DatabaseIcalTokenRepository implements IcalTokenRepositoryInterface
{
    public function __construct() {}

    public function findByToken(string $token): ?array
    {
        $record = EloquentIcalToken::where('token', $token)->first();
        return $record ? $record->toArray() : null;
    }

    public function findByUserAndStore(int $userId, int $storeId): ?array
    {
        $record = EloquentIcalToken::where('user_id', $userId)
            ->where('store_id', $storeId)
            ->first();
        return $record ? $record->toArray() : null;
    }

    public function findByUser(int $userId): array
    {
        return EloquentIcalToken::where('user_id', $userId)->get()->toArray();
    }

    public function save(array $data): array
    {
        if (!empty($data['id'])) {
            $record = EloquentIcalToken::findOrFail((int) $data['id']);
            $record->fill($data);
            $record->save();
        } else {
            $record = EloquentIcalToken::create($data);
        }
        return $record->toArray();
    }

    public function deleteByUserAndStore(int $userId, int $storeId): int
    {
        return EloquentIcalToken::where('user_id', $userId)
            ->where('store_id', $storeId)
            ->delete();
    }

    public function findOrCreateForUserAndStore(int $userId, int $storeId): array
    {
        $existing = $this->findByUserAndStore($userId, $storeId);
        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->save([
                'user_id'    => $userId,
                'store_id'   => $storeId,
                'token'      => bin2hex(random_bytes(32)),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            $existing = $this->findByUserAndStore($userId, $storeId);
            if ($existing !== null) {
                return $existing;
            }
            throw $e;
        }
    }
}
