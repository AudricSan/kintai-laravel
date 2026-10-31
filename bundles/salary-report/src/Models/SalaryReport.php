<?php

declare(strict_types=1);

namespace kintai\Bundles\Installed\SalaryReport\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Repris du Core à l'étape 4 du plan du contrat (extraction) : la table garde son nom et ses données.
 */
final class SalaryReport extends Model
{
    protected $table = 'salary_reports';
    protected $guarded = [];
    public $timestamps = false;

    public static function findById(int $id): ?array
    {
        $r = self::find($id);
        return $r ? $r->toArray() : null;
    }

    public static function findByStore(int $storeId): array
    {
        return self::where('store_id', $storeId)
            ->orderByDesc('target_month')
            ->get()
            ->toArray();
    }

    public static function findAll(?array $storeIds = null, array $filters = []): array
    {
        $q = self::orderByDesc('target_month');
        if ($storeIds !== null && $storeIds !== []) {
            $q->whereIn('store_id', $storeIds);
        }
        if (!empty($filters['year'])) {
            $q->where('target_month', 'LIKE', $filters['year'] . '-%');
        }
        if (!empty($filters['month'])) {
            $q->where('target_month', 'LIKE', '%-' . str_pad((string) $filters['month'], 2, '0', STR_PAD_LEFT));
        }
        if (!empty($filters['person_in_charge'])) {
            $q->where('person_in_charge', 'LIKE', '%' . $filters['person_in_charge'] . '%');
        }
        if (!empty($filters['store_id'])) {
            $q->where('store_id', (int) $filters['store_id']);
        }
        return $q->get()->toArray();
    }

    public static function findByStoreAndMonth(int $storeId, string $targetMonth, ?int $userId = null): ?array
    {
        $q = self::where('store_id', $storeId)
            ->where('target_month', $targetMonth);
        $userId !== null ? $q->where('user_id', $userId) : $q->whereNull('user_id');
        $r = $q->first();
        return $r ? $r->toArray() : null;
    }

    public static function put(array $data): array
    {
        if (!empty($data['id'])) {
            $r = self::findOrFail($data['id']);
            $r->fill($data);
            $r->save();
        } else {
            $r = self::create($data);
        }
        return $r->toArray();
    }

    public static function remove(int $id): int
    {
        $r = self::find($id);
        if ($r) {
            return $r->delete() ? 1 : 0;
        }
        return 0;
    }
}
