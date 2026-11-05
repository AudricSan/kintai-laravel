<?php

namespace App\Exports\Datasets;

use App\Exports\AbstractExportDataset;
use App\Models\Availability;
use Illuminate\Database\Eloquent\Builder;
use Kintai\Contracts\Exports\ExportColumn;
use Kintai\Contracts\Exports\ExportScope;

/**
 * « Availability » : les créneaux hebdomadaires déclarés par chaque employé, un par ligne.
 */
class AvailabilitiesDataset extends AbstractExportDataset
{
    public function key(): string
    {
        return 'availabilities';
    }

    public function label(): string
    {
        return 'Availability';
    }

    public function permission(): string
    {
        return 'shifts.view';
    }

    public function selfService(): bool
    {
        return true;
    }

    public function columns(): array
    {
        return [
            new ExportColumn('store', 'Store'),
            new ExportColumn('employee', 'Employee'),
            new ExportColumn('day_of_week', 'Day of the week (1 = Monday)', ExportColumn::INTEGER),
            new ExportColumn('start_time', 'Starts at', ExportColumn::TIME),
            new ExportColumn('end_time', 'Ends at', ExportColumn::TIME),
            new ExportColumn('available', 'Available', ExportColumn::BOOLEAN),
        ];
    }

    /**
     * @return Builder<Availability>
     */
    protected function query(ExportScope $scope): Builder
    {
        $query = Availability::query()
            ->with(['store', 'user' => fn ($user) => $user->withTrashed()])
            ->whereIn('availabilities.store_id', $scope->storeIds)
            ->join('stores', 'stores.id', '=', 'availabilities.store_id')
            ->join('users', 'users.id', '=', 'availabilities.user_id')
            ->select('availabilities.*')
            ->orderBy('stores.name')
            ->orderBy('users.name')
            ->orderBy('availabilities.user_id')
            ->orderBy('availabilities.day_of_week')
            ->orderBy('availabilities.id');

        $scope->applyUsers($query, 'availabilities.user_id');

        return $query;
    }

    protected function row(mixed $record): array
    {
        /** @var Availability $availability */
        $availability = $record;

        return [
            'store' => $availability->store->name,
            'employee' => $availability->user->name ?? '',
            'day_of_week' => $availability->day_of_week,
            'start_time' => $availability->starts_at === null ? '' : substr($availability->starts_at, 0, 5),
            'end_time' => $availability->ends_at === null ? '' : substr($availability->ends_at, 0, 5),
            'available' => $availability->is_available,
        ];
    }
}
