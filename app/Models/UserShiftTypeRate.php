<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class UserShiftTypeRate extends Model
{
    protected $table = 'user_shift_type_rates';
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

    public static function findByShiftType(int $shiftTypeId): array
    {
        return static::where('shift_type_id', $shiftTypeId)->get()->toArray();
    }

    public static function findRate(int $userId, int $shiftTypeId): ?array
    {
        $record = static::where('user_id', $userId)
            ->where('shift_type_id', $shiftTypeId)
            ->first();
        return $record ? $record->toArray() : null;
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
