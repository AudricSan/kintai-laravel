<?php

declare(strict_types=1);


namespace kintai\Core\Repositories;

use kintai\Domain\Eloquent\StoreUser as EloquentStoreUser;

final class DatabaseStoreUserRepository implements StoreUserRepositoryInterface
{
    public function __construct() {}

    public function findById(int $id): ?array
    {
        $row = EloquentStoreUser::find($id);
        return $row ? $row->toArray() : null;
    }

    public function findByStore(int $storeId): array
    {
        return EloquentStoreUser::where('store_id', $storeId)->get()->toArray();
    }

    public function findByUser(int $userId): array
    {
        return EloquentStoreUser::where('user_id', $userId)->get()->toArray();
    }

    public function findMembership(int $storeId, int $userId): ?array
    {
        $row = EloquentStoreUser::where('store_id', $storeId)
            ->where('user_id', $userId)
            ->first();
        return $row ? $row->toArray() : null;
    }

    public function save(array $data): array
    {
        if (!empty($data['id'])) {
            $row = EloquentStoreUser::findOrFail($data['id']);
            $row->fill($data);
            $row->save();
        } else {
            $row = EloquentStoreUser::create($data);
        }
        return $row->toArray();
    }

    public function delete(int $id): int
    {
        $row = EloquentStoreUser::find($id);
        if ($row) {
            return $row->delete() ? 1 : 0;
        }
        return 0;
    }

    public function getSubjectToDeductions(int $id): bool
    {
        $row = EloquentStoreUser::find($id);
        return $row ? (bool) $row->subject_to_deductions : false;
    }

    public function setSubjectToDeductions(int $id, bool $value): void
    {
        EloquentStoreUser::where('id', $id)->update(['subject_to_deductions' => $value ? 1 : 0]);
    }
}
