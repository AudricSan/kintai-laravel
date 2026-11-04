<?php

namespace App\Exports\Datasets;

use App\Exports\AbstractExportDataset;
use App\Exports\ExportColumn;
use App\Exports\ExportScope;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Planning\ShiftWageCalculator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * « Hours and costs » : le coût estimé de chaque shift, calculé comme partout ailleurs par `ShiftWageCalculator`
 * (taux du type, taux personnel prioritaire, surcharges manuelles du shift), sans aucune déduction (cotisations, impôts).
 * Donnée sensible : exigée sous `payroll.export`, jamais en libre-service.
 */
class PayrollDataset extends AbstractExportDataset
{
    /** @var array<int, Collection<int, ShiftType>> types actifs par magasin, relus une fois par export */
    private array $types = [];

    /** @var array<int, array<int, float>> taux personnels par employé */
    private array $rates = [];

    public function __construct(private readonly ShiftWageCalculator $calculator) {}

    public function key(): string
    {
        return 'payroll';
    }

    public function label(): string
    {
        return 'Hours and costs';
    }

    public function permission(): string
    {
        return 'payroll.export';
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
            new ExportColumn('shift_type', 'Shift type'),
            new ExportColumn('net_minutes', 'Net minutes', ExportColumn::INTEGER),
            new ExportColumn('hourly_rate', 'Hourly rate', ExportColumn::DECIMAL),
            new ExportColumn('amount', 'Estimated amount', ExportColumn::DECIMAL),
            new ExportColumn('currency', 'Currency'),
        ];
    }

    public function rows(ExportScope $scope): iterable
    {
        // Types et taux relus à chaque export : le jeu de données vit aussi longtemps que le conteneur.
        $this->types = [];
        $this->rates = [];

        return parent::rows($scope);
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
            ->orderBy('store_id')
            ->orderBy('starts_at')
            ->orderBy('id');

        $scope->applyUsers($query);

        return $query;
    }

    protected function row(mixed $record): array
    {
        /** @var Shift $shift */
        $shift = $record;

        $types = $this->types[$shift->store_id] ??= $this->calculator->activeTypes($shift->store_id);
        $rates = $this->rates[(int) $shift->user_id] ??= $this->calculator->personalRates($shift->user);
        $cost = $this->calculator->costOf($shift, $types, $rates);

        return [
            'date' => $shift->shift_date->toDateString(),
            'store' => $shift->store->name,
            'employee' => $shift->user->name ?? '',
            'shift_type' => $shift->shiftType->name ?? '',
            'net_minutes' => $cost->netMinutes,
            'hourly_rate' => round($cost->rate, 2),
            'amount' => round($cost->total, 2),
            'currency' => $shift->store->currency,
        ];
    }
}
