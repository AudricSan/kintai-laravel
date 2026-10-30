<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class DevicePushToken extends Model
{
    protected $table = 'device_push_tokens';
    protected $guarded = [];
    public $timestamps = false;

    public static function findByUser(int $userId): array
    {
        return static::where('user_id', $userId)->get()->toArray();
    }

    public static function findByToken(string $token): ?array
    {
        $record = static::where('token', $token)->first();
        return $record ? $record->toArray() : null;
    }

    public static function put(array $data): array
    {
        $record = isset($data['token']) ? static::where('token', $data['token'])->first() : null;
        if ($record !== null) {
            $record->fill($data);
            $record->save();
        } else {
            $record = static::create($data);
        }
        return $record->toArray();
    }

    public static function deleteByToken(string $token, ?int $userId = null): int
    {
        $query = static::where('token', $token);
        if ($userId !== null) {
            $query->where('user_id', $userId);
        }
        return $query->delete();
    }

    public static function touchLastUsed(int $id): void
    {
        static::where('id', $id)->update(['last_used_at' => date('Y-m-d H:i:s')]);
    }
}
