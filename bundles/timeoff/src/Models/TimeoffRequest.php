<?php
declare(strict_types=1);

namespace kintai\Bundles\Installed\TimeOff\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Repris du Core à l'étape 4 du plan du contrat (extraction) : la table garde son nom et ses données.
 */
final class TimeoffRequest extends Model
{
    protected $table = 'timeoff_requests';
    protected $guarded = [];
    public $timestamps = false;

    public static function findById(int $id): ?array
    {
        $record = self::find($id);
        return $record ? $record->toArray() : null;
    }

    public static function findByUser(int $userId): array
    {
        return self::where('user_id', $userId)->get()->toArray();
    }

    public static function findByStore(int $storeId): array
    {
        return self::where('store_id', $storeId)->get()->toArray();
    }

    public static function findByStatus(int $storeId, string $status): array
    {
        return self::where('store_id', $storeId)
            ->where('status', $status)
            ->get()
            ->toArray();
    }

    public static function findAll(): array
    {
        return self::all()->toArray();
    }

    public static function put(array $data): array
    {
        if (!empty($data['id'])) {
            $record = self::findOrFail((int) $data['id']);
            $record->fill($data);
            $record->save();
        } else {
            $record = self::create($data);
        }
        return $record->toArray();
    }

    public static function remove(int $id): int
    {
        $record = self::find($id);
        return $record ? ($record->delete() ? 1 : 0) : 0;
    }
}
