<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class Availability extends Model
{
    protected $table = 'availabilities';
    protected $guarded = [];
    public $timestamps = false;

    public static function findById(int $id): ?array
    {
        $record = static::find($id);
        return $record ? $record->toArray() : null;
    }

    public static function findByUser(int $userId): array
    {
        return static::where('user_id', $userId)->get()->toArray();
    }

    public static function findByStore(int $storeId): array
    {
        return static::where('store_id', $storeId)->get()->toArray();
    }

    public static function findByUserAndStore(int $userId, int $storeId): array
    {
        return static::where('user_id', $userId)
            ->where('store_id', $storeId)
            ->get()
            ->toArray();
    }

    public static function deleteByUserAndStore(int $userId, int $storeId): int
    {
        return static::where('user_id', $userId)
            ->where('store_id', $storeId)
            ->delete();
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

    public static function remove(int $id): int
    {
        $record = static::find($id);
        return $record ? ($record->delete() ? 1 : 0) : 0;
    }
}
