<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class StoreUser extends Model
{
    protected $table = 'store_user';
    protected $guarded = [];
    public $timestamps = false;

    public static function findById(int $id): ?array
    {
        $row = static::find($id);
        return $row ? $row->toArray() : null;
    }

    public static function findByStore(int $storeId): array
    {
        return static::where('store_id', $storeId)->get()->toArray();
    }

    public static function findByUser(int $userId): array
    {
        return static::where('user_id', $userId)->get()->toArray();
    }

    public static function findMembership(int $storeId, int $userId): ?array
    {
        $row = static::where('store_id', $storeId)
            ->where('user_id', $userId)
            ->first();
        return $row ? $row->toArray() : null;
    }

    public static function put(array $data): array
    {
        if (!empty($data['id'])) {
            $row = static::findOrFail($data['id']);
            $row->fill($data);
            $row->save();
        } else {
            $row = static::create($data);
        }
        return $row->toArray();
    }

    public static function remove(int $id): int
    {
        $row = static::find($id);
        if ($row) {
            return $row->delete() ? 1 : 0;
        }
        return 0;
    }

    public static function getSubjectToDeductions(int $id): bool
    {
        $row = static::find($id);
        return $row ? (bool) $row->subject_to_deductions : false;
    }

    public static function setSubjectToDeductions(int $id, bool $value): void
    {
        static::where('id', $id)->update(['subject_to_deductions' => $value ? 1 : 0]);
    }
}
