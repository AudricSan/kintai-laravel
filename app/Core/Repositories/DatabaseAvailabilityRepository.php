<?php
declare(strict_types=1);

namespace kintai\Core\Repositories;

use kintai\Domain\Eloquent\Availability as EloquentAvailability;

final class DatabaseAvailabilityRepository implements AvailabilityRepositoryInterface
{
    public function __construct() {}

    public function findById(int $id): ?array
    {
        $record = EloquentAvailability::find($id);
        return $record ? $record->toArray() : null;
    }

    public function findByUser(int $userId): array
    {
        return EloquentAvailability::where('user_id', $userId)->get()->toArray();
    }

    public function findByStore(int $storeId): array
    {
        return EloquentAvailability::where('store_id', $storeId)->get()->toArray();
    }

    public function findByUserAndStore(int $userId, int $storeId): array
    {
        return EloquentAvailability::where('user_id', $userId)
            ->where('store_id', $storeId)
            ->get()
            ->toArray();
    }

    public function deleteByUserAndStore(int $userId, int $storeId): int
    {
        return EloquentAvailability::where('user_id', $userId)
            ->where('store_id', $storeId)
            ->delete();
    }

    public function save(array $data): array
    {
        if (!empty($data['id'])) {
            $record = EloquentAvailability::findOrFail((int) $data['id']);
            $record->fill($data);
            $record->save();
        } else {
            $record = EloquentAvailability::create($data);
        }
        return $record->toArray();
    }

    public function delete(int $id): int
    {
        $record = EloquentAvailability::find($id);
        return $record ? ($record->delete() ? 1 : 0) : 0;
    }
}
