<?php

namespace App\Exports\Datasets;

use App\Exports\AbstractExportDataset;
use App\Exports\ExportColumn;
use App\Exports\ExportScope;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;

/**
 * « Stores » : une ligne par magasin autorisé. Pas de réglage interne.
 */
class StoresDataset extends AbstractExportDataset
{
    public function key(): string
    {
        return 'stores';
    }

    public function label(): string
    {
        return 'Stores';
    }

    public function permission(): string
    {
        return 'stores.view';
    }

    public function perEmployee(): bool
    {
        return false;
    }

    public function columns(): array
    {
        return [
            new ExportColumn('store_id', 'Store ID', ExportColumn::INTEGER),
            new ExportColumn('code', 'Code'),
            new ExportColumn('name', 'Name'),
            new ExportColumn('timezone', 'Time zone'),
            new ExportColumn('locale', 'Language'),
            new ExportColumn('currency', 'Currency'),
            new ExportColumn('active', 'Active', ExportColumn::BOOLEAN),
        ];
    }

    /**
     * @return Builder<Store>
     */
    protected function query(ExportScope $scope): Builder
    {
        return Store::query()->whereIn('id', $scope->storeIds)->orderBy('name')->orderBy('id');
    }

    protected function row(mixed $record): array
    {
        /** @var Store $store */
        $store = $record;

        return [
            'store_id' => $store->id,
            'code' => $store->code,
            'name' => $store->name,
            'timezone' => $store->timezone,
            'locale' => $store->locale ?? '',
            'currency' => $store->currency,
            'active' => $store->is_active,
        ];
    }
}
