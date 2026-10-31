<?php

declare(strict_types=1);

namespace kintai\Bundles\Installed\HiringReport\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Repris du Core à l'étape 4 du plan du contrat (extraction) : la table garde son nom et ses données.
 */
final class HiringReport extends Model
{
    protected $table = 'hiring_reports';
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
            ->orderByDesc('created_at')
            ->get()
            ->toArray();
    }

    public static function findAll(?array $storeIds = null, array $filters = []): array
    {
        $q = self::orderByDesc('created_at');
        if ($storeIds !== null && $storeIds !== []) {
            $q->whereIn('store_id', $storeIds);
        }
        if (!empty($filters['year'])) {
            $q->whereYear('hire_date', (int) $filters['year']);
        }
        if (!empty($filters['month'])) {
            $q->whereMonth('hire_date', (int) $filters['month']);
        }
        if (!empty($filters['store_id'])) {
            $q->where('store_id', (int) $filters['store_id']);
        }
        return $q->get()->toArray();
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
