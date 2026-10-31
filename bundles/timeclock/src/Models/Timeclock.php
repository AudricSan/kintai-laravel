<?php
declare(strict_types=1);

namespace kintai\Bundles\Installed\Timeclock\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Repris du Core à l'étape 4 du plan du contrat (extraction) : la table garde son nom et ses données.
 */
final class Timeclock extends Model
{
    protected $table = 'timeclocks';
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

    public static function findByStoreBetween(int $storeId, string $from, string $to): array
    {
        // Seule la période est lue (index store_id, shift_date), pas tout l'historique des pointages.
        return self::where('store_id', $storeId)
            ->whereBetween('shift_date', [$from, $to])
            ->orderBy('id')
            ->get()
            ->toArray();
    }

    public static function findByUserAndDate(int $userId, string $date): array
    {
        return self::where('user_id', $userId)
            ->where('shift_date', $date)
            ->get()
            ->toArray();
    }

    public static function findByStoreAndDate(int $storeId, string $date): array
    {
        return self::where('store_id', $storeId)
            ->where('shift_date', $date)
            ->get()
            ->toArray();
    }

    public static function findActiveByUser(int $userId): ?array
    {
        $record = self::where('user_id', $userId)
            ->whereNull('clock_out_time')
            ->first();
        return $record ? $record->toArray() : null;
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
