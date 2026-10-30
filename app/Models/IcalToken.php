<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class IcalToken extends Model
{
    protected $table = 'ical_tokens';
    protected $guarded = [];
    public $timestamps = false;

    public static function findByToken(string $token): ?array
    {
        $record = static::where('token', $token)->first();
        return $record ? $record->toArray() : null;
    }

    public static function findByUserAndStore(int $userId, int $storeId): ?array
    {
        $record = static::where('user_id', $userId)
            ->where('store_id', $storeId)
            ->first();
        return $record ? $record->toArray() : null;
    }

    public static function findByUser(int $userId): array
    {
        return static::where('user_id', $userId)->get()->toArray();
    }

    public static function put(array $data): array
    {
        if (!empty($data['id'])) {
            $record = static::findOrFail((int) $data['id']);
            $record->fill($data);
            $record->save();
        } else {
            $record = static::create($data);
        }
        return $record->toArray();
    }

    public static function deleteByUserAndStore(int $userId, int $storeId): int
    {
        return static::where('user_id', $userId)
            ->where('store_id', $storeId)
            ->delete();
    }

    public static function findOrCreateForUserAndStore(int $userId, int $storeId): array
    {
        $existing = static::findByUserAndStore($userId, $storeId);
        if ($existing !== null) {
            return $existing;
        }

        try {
            return static::put([
                'user_id'    => $userId,
                'store_id'   => $storeId,
                'token'      => bin2hex(random_bytes(32)),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            $existing = static::findByUserAndStore($userId, $storeId);
            if ($existing !== null) {
                return $existing;
            }
            throw $e;
        }
    }
}
