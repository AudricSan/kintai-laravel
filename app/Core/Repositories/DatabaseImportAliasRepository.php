<?php
declare(strict_types=1);

namespace kintai\Core\Repositories;

use kintai\Domain\Eloquent\ImportAlias as EloquentImportAlias;

final class DatabaseImportAliasRepository implements ImportAliasRepositoryInterface
{
    public function __construct() {}

    public function findByStore(int $storeId): array
    {
        $rows = EloquentImportAlias::where('store_id', $storeId)->get();
        $map = [];
        foreach ($rows as $row) {
            $map[$row['staff_name']] = (int) $row['user_id'];
        }
        return $map;
    }

    public function upsert(int $storeId, string $staffName, int $userId): void
    {
        EloquentImportAlias::updateOrCreate(
            ['store_id' => $storeId, 'staff_name' => $staffName],
            ['user_id' => $userId],
        );
    }

    public function deleteAlias(int $storeId, string $staffName): void
    {
        EloquentImportAlias::where('store_id', $storeId)
            ->where('staff_name', $staffName)
            ->delete();
    }
}
