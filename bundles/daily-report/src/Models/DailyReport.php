<?php
declare(strict_types=1);

namespace kintai\Bundles\Installed\DailyReport\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Repris du Core à l'étape 4 du plan du contrat (extraction) : la table garde son nom et ses données.
 */
final class DailyReport extends Model
{
    protected $table = 'daily_reports';
    protected $guarded = [];
    public $timestamps = false;

    public static function findById(int $id): ?array
    {
        $record = self::whereNull('deleted_at')->find($id);
        return $record ? $record->toArray() : null;
    }

    public static function findByStore(int $storeId): array
    {
        return self::where('store_id', $storeId)
            ->whereNull('deleted_at')
            ->orderBy('report_date', 'desc')
            ->get()
            ->toArray();
    }

    public static function findByStoreAndDate(int $storeId, string $date): ?array
    {
        $record = self::where('store_id', $storeId)
            ->where('report_date', $date)
            ->whereNull('deleted_at')
            ->first();
        return $record ? $record->toArray() : null;
    }

    public static function findByAuthor(int $authorId): array
    {
        return self::where('author_id', $authorId)
            ->whereNull('deleted_at')
            ->orderBy('report_date', 'desc')
            ->get()
            ->toArray();
    }

    public static function findByStoreAndStatus(int $storeId, string $status): array
    {
        return self::where('store_id', $storeId)
            ->where('status', $status)
            ->whereNull('deleted_at')
            ->orderBy('report_date', 'desc')
            ->get()
            ->toArray();
    }

    public static function findByStoreAndDateRange(int $storeId, string $from, string $to): array
    {
        return self::where('store_id', $storeId)
            ->whereNull('deleted_at')
            ->where('report_date', '>=', $from)
            ->where('report_date', '<=', $to)
            ->orderBy('report_date', 'desc')
            ->get()
            ->toArray();
    }

    public static function findAllActive(array $storeIds = []): array
    {
        $query = self::whereNull('deleted_at');
        if (!empty($storeIds)) {
            $query->whereIn('store_id', $storeIds);
        }
        return $query->orderBy('report_date', 'desc')->get()->toArray();
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
