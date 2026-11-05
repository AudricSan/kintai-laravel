<?php

namespace App\Exports\Datasets;

use App\Exports\AbstractExportDataset;
use App\Exports\CalendarExportDataset;
use App\Exports\ExportWriter;
use App\Ical\IcalCalendar;
use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Kintai\Contracts\Exports\ExportColumn;
use Kintai\Contracts\Exports\ExportScope;
use Kintai\Contracts\Planning\TimeOffProvider;

/**
 * « Shifts » : un shift assigné par ligne, par ordre chronologique (shifts supprimés et shifts ouverts exclus).
 * Les horaires sont ceux de l'instance (`kintai.timezone`).
 */
class ShiftsDataset extends AbstractExportDataset implements CalendarExportDataset
{
    public function __construct(private readonly TimeOffProvider $timeOff) {}

    public function key(): string
    {
        return 'shifts';
    }

    public function label(): string
    {
        return 'Shifts';
    }

    public function permission(): string
    {
        return 'shifts.view';
    }

    public function selfService(): bool
    {
        return true;
    }

    public function hasPeriod(): bool
    {
        return true;
    }

    public function formats(): array
    {
        return [ExportWriter::CSV, ExportWriter::XLSX, ExportWriter::PDF, ExportWriter::ICS];
    }

    public function columns(): array
    {
        return [
            new ExportColumn('date', 'Date', ExportColumn::DATE),
            new ExportColumn('start_time', 'Start', ExportColumn::TIME),
            new ExportColumn('end_time', 'End', ExportColumn::TIME),
            new ExportColumn('ends_next_day', 'Ends the next day', ExportColumn::BOOLEAN),
            new ExportColumn('break_minutes', 'Break (min)', ExportColumn::INTEGER),
            new ExportColumn('net_minutes', 'Net minutes', ExportColumn::INTEGER),
            new ExportColumn('shift_type', 'Shift type'),
            new ExportColumn('employee', 'Employee'),
            new ExportColumn('store', 'Store'),
            new ExportColumn('notes', 'Notes'),
        ];
    }

    /**
     * @return Builder<Shift>
     */
    protected function query(ExportScope $scope): Builder
    {
        $query = Shift::query()
            ->with(['store', 'shiftType', 'user' => fn ($user) => $user->withTrashed()])
            ->whereNotNull('user_id')
            ->whereIn('store_id', $scope->storeIds)
            ->whereBetween('shift_date', [$scope->from->toDateString(), $scope->to->toDateString()])
            ->orderBy('shift_date')
            ->orderBy('starts_at')
            ->orderBy('id');

        $scope->applyUsers($query);

        return $query;
    }

    protected function row(mixed $record): array
    {
        /** @var Shift $shift */
        $shift = $record;

        return [
            'date' => $shift->shift_date->toDateString(),
            'start_time' => $shift->localStartsAt->format('H:i'),
            'end_time' => $shift->localEndsAt->format('H:i'),
            'ends_next_day' => $shift->crossesMidnight,
            'break_minutes' => $shift->pause_minutes,
            'net_minutes' => $shift->netMinutes,
            'shift_type' => $shift->shiftType->name ?? '',
            'employee' => $shift->user->name ?? '',
            'store' => $shift->store->name,
            'notes' => $shift->notes ?? '',
        ];
    }

    public function canBeCalendar(ExportScope $scope): bool
    {
        return $scope->userIds !== null && count($scope->userIds) === 1;
    }

    public function calendar(ExportScope $scope): string
    {
        $shifts = $this->query($scope)->get();
        $name = $shifts->first()?->user->name ?? __('Shifts');

        $calendar = new IcalCalendar($name, CarbonImmutable::now());
        foreach ($shifts as $shift) {
            $calendar->addShift($shift);
        }

        // Congés approuvés de l'employé (aucun sans bundle de congés), en journée entière.
        $employee = $scope->userIds === null || count($scope->userIds) !== 1 ? null : User::query()->find($scope->userIds[0]);
        if ($employee !== null) {
            foreach (Store::query()->whereIn('id', $scope->storeIds)->orderBy('name')->get() as $store) {
                foreach ($this->timeOff->approvedFor($employee->id, $store->id, $scope->from, $scope->to) as $period) {
                    $calendar->addTimeOff($period, $store->name);
                }
            }
        }

        return $calendar->render();
    }
}
