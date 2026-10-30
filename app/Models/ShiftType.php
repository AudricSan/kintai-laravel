<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Casts\HexColor;

final class ShiftType extends Model
{
    protected $table = 'shift_types';
    protected $guarded = [];
    public $timestamps = false;

    // Une couleur invalide en base est remplacée à la lecture (voir HexColor).
    protected $casts = ['color' => HexColor::class . ':#3B82F6'];

    public static function findById(int $id): ?array
    {
        $type = static::find($id);
        return $type ? $type->toArray() : null;
    }

    public static function findByStore(int $storeId): array
    {
        return static::query()
            ->join('shift_type_stores', 'shift_type_stores.shift_type_id', '=', 'shift_types.id')
            ->where('shift_type_stores.store_id', $storeId)
            ->select('shift_types.*')
            ->get()
            ->toArray();
    }

    public static function findByStores(array $storeIds): array
    {
        if ($storeIds === []) {
            return [];
        }
        return static::query()
            ->join('shift_type_stores', 'shift_type_stores.shift_type_id', '=', 'shift_types.id')
            ->whereIn('shift_type_stores.store_id', $storeIds)
            ->select('shift_types.*')
            ->distinct()
            ->get()
            ->toArray();
    }

    public static function findActive(int $storeId): array
    {
        return static::query()
            ->join('shift_type_stores', 'shift_type_stores.shift_type_id', '=', 'shift_types.id')
            ->where('shift_type_stores.store_id', $storeId)
            ->where('shift_types.is_active', 1)
            ->select('shift_types.*')
            ->get()
            ->toArray();
    }

    public static function findAll(): array
    {
        return static::all()->toArray();
    }

    public static function put(array $data): array
    {
        if (!empty($data['id'])) {
            $type = static::findOrFail($data['id']);
            $type->fill($data);
            $type->save();
        } else {
            $type = static::create($data);
        }
        return $type->toArray();
    }

    public static function remove(int $id): int
    {
        // SQLite (config par défaut de cette appli, voir onDelete('cascade') non
        // appliqué) n'enforce pas la cascade FK ici : suppression explicite des
        // affectations avant celle du type.
        ShiftTypeStore::where('shift_type_id', $id)->delete();

        $type = static::find($id);
        if ($type) {
            return $type->delete() ? 1 : 0;
        }
        return 0;
    }

    public static function getStoreIds(int $shiftTypeId): array
    {
        return ShiftTypeStore::where('shift_type_id', $shiftTypeId)
            ->pluck('store_id')
            ->map(fn ($id) => (int) $id)
            ->toArray();
    }

    public static function syncStores(int $shiftTypeId, array $storeIds): void
    {
        ShiftTypeStore::where('shift_type_id', $shiftTypeId)->delete();
        foreach (array_unique(array_map('intval', $storeIds)) as $storeId) {
            ShiftTypeStore::create(['shift_type_id' => $shiftTypeId, 'store_id' => $storeId]);
        }
    }

    public static function isEnabledForStore(int $shiftTypeId, int $storeId): bool
    {
        return ShiftTypeStore::where('shift_type_id', $shiftTypeId)->where('store_id', $storeId)->exists();
    }

    public static function enableForStore(int $shiftTypeId, int $storeId): void
    {
        ShiftTypeStore::firstOrCreate(['shift_type_id' => $shiftTypeId, 'store_id' => $storeId]);
    }

    public static function disableForStore(int $shiftTypeId, int $storeId): void
    {
        ShiftTypeStore::where('shift_type_id', $shiftTypeId)->where('store_id', $storeId)->delete();
    }
}
