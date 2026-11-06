<?php

namespace Kintai\Bundles\Timeclock;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kintai\Contracts\Exports\ExportColumn;
use Kintai\Contracts\Exports\ExportDataset;
use Kintai\Contracts\Exports\ExportScope;

/**
 * Jeu de données « Time clock » des exports : une ligne par pointage de la période (jour de l'entrée), pour les magasins et
 * les employés que le Core autorise (`ExportScope`). Chacun peut exporter les siens ; les autres demandent `timeclock.view`.
 */
class TimeclockExport implements ExportDataset
{
    public function key(): string
    {
        return 'timeclock';
    }

    public function label(): string
    {
        return 'Time clock';
    }

    public function permission(): string
    {
        return 'timeclock.view';
    }

    public function selfService(): bool
    {
        return true;
    }

    public function perEmployee(): bool
    {
        return true;
    }

    public function hasPeriod(): bool
    {
        return true;
    }

    public function columns(): array
    {
        return [
            new ExportColumn('date', 'Date', ExportColumn::DATE),
            new ExportColumn('store', 'Store'),
            new ExportColumn('employee', 'Employee'),
            new ExportColumn('clock_in', 'Clock-in', ExportColumn::TIME),
            new ExportColumn('clock_out', 'Clock-out', ExportColumn::TIME),
            new ExportColumn('minutes', 'Duration (min)', ExportColumn::INTEGER),
            new ExportColumn('overtime_minutes', 'Overtime (min)', ExportColumn::INTEGER),
            new ExportColumn('notes', 'Notes'),
        ];
    }

    public function formats(): array
    {
        return ['csv', 'xlsx', 'pdf'];
    }

    public function count(ExportScope $scope): int
    {
        return $this->query($scope)->count();
    }

    public function rows(ExportScope $scope): iterable
    {
        $timezone = (string) config('kintai.timezone', 'UTC');
        $overtime = app(OvertimeTracker::class);

        foreach ($this->query($scope)->orderBy('timeclocks.shift_date')->orderBy('timeclocks.clock_in_at')->orderBy('timeclocks.id')->cursor() as $row) {
            yield [
                'date' => substr((string) $row->shift_date, 0, 10),
                'store' => $row->store_name,
                'employee' => $row->user_name,
                'clock_in' => $this->local($row->clock_in_at, $timezone),
                'clock_out' => $row->clock_out_at === null ? null : $this->local($row->clock_out_at, $timezone),
                'minutes' => $row->duration_minutes === null ? null : (int) $row->duration_minutes,
                'overtime_minutes' => $overtime->rules()->active() ? $overtime->cachedReport((int) $row->user_id, Carbon::parse($row->clock_in_at, 'UTC'))->forEntry((int) $row->id) : null,
                'notes' => $row->notes,
            ];
        }
    }

    private function local(string $utc, string $timezone): string
    {
        return Carbon::parse($utc, 'UTC')->timezone($timezone)->format('H:i');
    }

    private function query(ExportScope $scope): Builder
    {
        $query = DB::table('timeclocks')
            ->join('users', 'users.id', '=', 'timeclocks.user_id')
            ->join('stores', 'stores.id', '=', 'timeclocks.store_id')
            ->whereIn('timeclocks.store_id', $scope->storeIds)
            ->whereDate('timeclocks.shift_date', '>=', $scope->from->toDateString())
            ->whereDate('timeclocks.shift_date', '<=', $scope->to->toDateString())
            ->select([
                'timeclocks.id', 'timeclocks.user_id', 'timeclocks.shift_date', 'timeclocks.clock_in_at', 'timeclocks.clock_out_at', 'timeclocks.duration_minutes', 'timeclocks.notes',
                'stores.name as store_name', 'users.name as user_name',
            ]);

        $scope->applyUsers($query, 'timeclocks.user_id');

        return $query;
    }
}
