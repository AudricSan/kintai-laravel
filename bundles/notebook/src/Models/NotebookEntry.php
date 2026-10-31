<?php

declare(strict_types=1);

namespace kintai\Bundles\Installed\Notebook\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Note du carnet d'équipe (table notebook_entries, créée par la migration du bundle). Repris du Core à l'étape 4 du
 * plan du contrat (extraction) : la table garde son nom et ses données.
 */
final class NotebookEntry extends Model
{
    protected $table = 'notebook_entries';
    protected $guarded = [];
    public $timestamps = false;

    public static function findById(int $id): ?array
    {
        $record = self::find($id);
        return $record ? $record->toArray() : null;
    }

    public static function findByStore(int $storeId): array
    {
        return self::where('store_id', $storeId)->get()->toArray();
    }

    public static function findAll(): array
    {
        return self::all()->toArray();
    }

    public static function findVisibleForStores(array $storeIds): array
    {
        return self::where(function ($query) use ($storeIds) {
            $query->whereNull('store_id');
            if ($storeIds !== []) {
                $query->orWhereIn('store_id', $storeIds);
            }
        })->get()->toArray();
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
