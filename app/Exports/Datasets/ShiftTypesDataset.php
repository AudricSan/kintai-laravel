<?php

namespace App\Exports\Datasets;

use App\Exports\AbstractExportDataset;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Kintai\Contracts\Exports\ExportColumn;
use Kintai\Contracts\Exports\ExportScope;

/**
 * « Shift types » : un type par ligne et par magasin où il est activé. Les taux horaires n'y figurent pas : ce sont des
 * données de paie, exportées par le jeu « Hours and costs » sous sa propre permission.
 */
class ShiftTypesDataset extends AbstractExportDataset
{
    public function key(): string
    {
        return 'shift_types';
    }

    public function label(): string
    {
        return 'Shift types';
    }

    public function permission(): string
    {
        return 'shifts.view';
    }

    public function perEmployee(): bool
    {
        return false;
    }

    public function columns(): array
    {
        return [
            new ExportColumn('store', 'Store'),
            new ExportColumn('type_id', 'Type ID', ExportColumn::INTEGER),
            new ExportColumn('name', 'Name'),
            new ExportColumn('code', 'Code'),
            new ExportColumn('start_time', 'Starts at', ExportColumn::TIME),
            new ExportColumn('end_time', 'Ends at', ExportColumn::TIME),
            new ExportColumn('active', 'Active', ExportColumn::BOOLEAN),
        ];
    }

    protected function query(ExportScope $scope): Builder
    {
        return DB::table('shift_type_store')
            ->join('shift_types', 'shift_types.id', '=', 'shift_type_store.shift_type_id')
            ->join('stores', 'stores.id', '=', 'shift_type_store.store_id')
            ->whereIn('shift_type_store.store_id', $scope->storeIds)
            ->select(['stores.name as store_name', 'shift_types.id', 'shift_types.name', 'shift_types.code', 'shift_types.starts_at', 'shift_types.ends_at', 'shift_types.is_active'])
            ->orderBy('stores.name')
            ->orderBy('shift_types.sort_order')
            ->orderBy('shift_types.starts_at')
            ->orderBy('shift_types.id');
    }

    protected function row(mixed $record): array
    {
        return [
            'store' => $record->store_name,
            'type_id' => (int) $record->id,
            'name' => $record->name,
            'code' => $record->code,
            'start_time' => substr((string) $record->starts_at, 0, 5),
            'end_time' => substr((string) $record->ends_at, 0, 5),
            'active' => (bool) $record->is_active,
        ];
    }
}
