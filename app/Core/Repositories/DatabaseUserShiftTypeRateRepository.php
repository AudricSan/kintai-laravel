<?php
declare(strict_types=1);

namespace kintai\Core\Repositories;

use kintai\Domain\Eloquent\UserShiftTypeRate as EloquentUserShiftTypeRate;

final class DatabaseUserShiftTypeRateRepository implements UserShiftTypeRateRepositoryInterface
{
    public function __construct() {}

    public function findById(int $id): ?array
    {
        $record = EloquentUserShiftTypeRate::find($id);
        return $record ? $record->toArray() : null;
    }

    public function findByUser(int $userId): array
    {
        return EloquentUserShiftTypeRate::where('user_id', $userId)->get()->toArray();
    }

    public function findByShiftType(int $shiftTypeId): array
    {
        return EloquentUserShiftTypeRate::where('shift_type_id', $shiftTypeId)->get()->toArray();
    }

    public function findRate(int $userId, int $shiftTypeId): ?array
    {
        $record = EloquentUserShiftTypeRate::where('user_id', $userId)
            ->where('shift_type_id', $shiftTypeId)
            ->first();
        return $record ? $record->toArray() : null;
    }

    public function save(array $data): array
    {
        if (!empty($data['id'])) {
            $record = EloquentUserShiftTypeRate::findOrFail((int) $data['id']);
            $record->fill($data);
            $record->save();
        } else {
            $record = EloquentUserShiftTypeRate::create($data);
        }
        return $record->toArray();
    }

    public function delete(int $id): int
    {
        $record = EloquentUserShiftTypeRate::find($id);
        return $record ? ($record->delete() ? 1 : 0) : 0;
    }
}
