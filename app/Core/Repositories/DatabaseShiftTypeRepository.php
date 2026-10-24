<?php

declare(strict_types=1);


namespace kintai\Core\Repositories;

use kintai\Domain\Eloquent\ShiftType as EloquentShiftType;
use kintai\Domain\Eloquent\ShiftTypeStore;

final class DatabaseShiftTypeRepository implements ShiftTypeRepositoryInterface
{
    public function __construct() {}

    public function findById(int $id): ?array
    {
        $type = EloquentShiftType::find($id);
        return $type ? $type->toArray() : null;
    }

    public function findByStore(int $storeId): array
    {
        return EloquentShiftType::query()
            ->join('shift_type_stores', 'shift_type_stores.shift_type_id', '=', 'shift_types.id')
            ->where('shift_type_stores.store_id', $storeId)
            ->select('shift_types.*')
            ->get()
            ->toArray();
    }

    public function findByStores(array $storeIds): array
    {
        if ($storeIds === []) {
            return [];
        }
        return EloquentShiftType::query()
            ->join('shift_type_stores', 'shift_type_stores.shift_type_id', '=', 'shift_types.id')
            ->whereIn('shift_type_stores.store_id', $storeIds)
            ->select('shift_types.*')
            ->distinct()
            ->get()
            ->toArray();
    }

    public function findActive(int $storeId): array
    {
        return EloquentShiftType::query()
            ->join('shift_type_stores', 'shift_type_stores.shift_type_id', '=', 'shift_types.id')
            ->where('shift_type_stores.store_id', $storeId)
            ->where('shift_types.is_active', 1)
            ->select('shift_types.*')
            ->get()
            ->toArray();
    }

    public function findAll(): array
    {
        return EloquentShiftType::all()->toArray();
    }

    public function save(array $data): array
    {
        if (!empty($data['id'])) {
            $type = EloquentShiftType::findOrFail($data['id']);
            $type->fill($data);
            $type->save();
        } else {
            $type = EloquentShiftType::create($data);
        }
        return $type->toArray();
    }

    public function delete(int $id): int
    {
        // SQLite (config par défaut de cette appli, voir onDelete('cascade') non
        // appliqué) n'enforce pas la cascade FK ici : suppression explicite des
        // affectations avant celle du type.
        ShiftTypeStore::where('shift_type_id', $id)->delete();

        $type = EloquentShiftType::find($id);
        if ($type) {
            return $type->delete() ? 1 : 0;
        }
        return 0;
    }

    public function getStoreIds(int $shiftTypeId): array
    {
        return ShiftTypeStore::where('shift_type_id', $shiftTypeId)
            ->pluck('store_id')
            ->map(fn ($id) => (int) $id)
            ->toArray();
    }

    public function syncStores(int $shiftTypeId, array $storeIds): void
    {
        ShiftTypeStore::where('shift_type_id', $shiftTypeId)->delete();
        foreach (array_unique(array_map('intval', $storeIds)) as $storeId) {
            ShiftTypeStore::create(['shift_type_id' => $shiftTypeId, 'store_id' => $storeId]);
        }
    }

    public function isEnabledForStore(int $shiftTypeId, int $storeId): bool
    {
        return ShiftTypeStore::where('shift_type_id', $shiftTypeId)->where('store_id', $storeId)->exists();
    }

    public function enableForStore(int $shiftTypeId, int $storeId): void
    {
        ShiftTypeStore::firstOrCreate(['shift_type_id' => $shiftTypeId, 'store_id' => $storeId]);
    }

    public function disableForStore(int $shiftTypeId, int $storeId): void
    {
        ShiftTypeStore::where('shift_type_id', $shiftTypeId)->where('store_id', $storeId)->delete();
    }
}
