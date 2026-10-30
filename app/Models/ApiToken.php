<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ApiToken extends Model
{
    protected $table = 'api_tokens';
    protected $guarded = [];
    public $timestamps = false;

    public static function findById(int $id): ?array
    {
        $record = static::find($id);
        return $record ? $record->toArray() : null;
    }

    public static function findByToken(string $token): ?array
    {
        $record = static::where('token', $token)->first();
        return $record ? $record->toArray() : null;
    }

    public static function findByUserId(int $userId): array
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

    public static function remove(int $id): int
    {
        $record = static::find($id);
        return $record ? ($record->delete() ? 1 : 0) : 0;
    }

    public static function deleteByUserId(int $userId): int
    {
        return static::where('user_id', $userId)->delete();
    }
}
