<?php

declare(strict_types=1);

namespace kintai\Core\Repositories;

use kintai\Domain\Eloquent\ShiftWeekTemplate;

final class DatabaseShiftWeekTemplateRepository implements ShiftWeekTemplateRepositoryInterface
{
    public function findById(int $id): ?array
    {
        $row = ShiftWeekTemplate::find($id);
        return $row ? self::toArray($row) : null;
    }

    public function findByStore(int $storeId): array
    {
        return ShiftWeekTemplate::where('store_id', $storeId)
            ->orderBy('name')
            ->get()
            ->map(static fn(ShiftWeekTemplate $row): array => self::toArray($row))
            ->all();
    }

    public function save(int $storeId, string $name, array $entries, ?int $createdBy): array
    {
        $row = ShiftWeekTemplate::where('store_id', $storeId)->where('name', $name)->first() ?? new ShiftWeekTemplate();
        $row->fill([
            'store_id'   => $storeId,
            'name'       => $name,
            'entries'    => json_encode(array_values($entries), JSON_THROW_ON_ERROR),
            'created_by' => $createdBy,
        ]);
        $row->save();
        return self::toArray($row);
    }

    public function delete(int $id): int
    {
        return ShiftWeekTemplate::where('id', $id)->delete();
    }

    /** @return array{id: int, store_id: int, name: string, entries: list<array<string, mixed>>, created_by: ?int} */
    private static function toArray(ShiftWeekTemplate $row): array
    {
        $entries = json_decode((string) $row->entries, true);
        return [
            'id'         => (int) $row->id,
            'store_id'   => (int) $row->store_id,
            'name'       => (string) $row->name,
            'entries'    => is_array($entries) ? array_values($entries) : [],
            'created_by' => $row->created_by !== null ? (int) $row->created_by : null,
        ];
    }
}
