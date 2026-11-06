<?php

namespace Kintai\Bundles\SalaryReport;

use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use App\Planning\ShiftWageCalculator;
use Kintai\Bundles\SalaryReport\Models\SalaryReport;
use Kintai\Bundles\SalaryReport\Models\SalaryReportMember;
use Kintai\Bundles\SalaryReport\Models\SalaryReportSetting;

/**
 * Calcul d'un rapport de salaire à partir des shifts d'un magasin sur une période : heures, coût (par `ShiftWageCalculator`,
 * la source unique du Core), répartition par type d'horaire et retenues du magasin. Pur calcul : ne vérifie aucun droit
 * (c'est le rôle de l'appelant, voir `SalaryReportActions`) et ne persiste rien.
 *
 * Les ventes ne sont pas reprises : le Core n'a pas de fournisseur de ventes, `total_payment` se saisit à la main.
 */
class SalaryCalculator
{
    public function __construct(private readonly ShiftWageCalculator $wages) {}

    /**
     * `preset` : valeurs proposées au formulaire ; `detail` : décomposition affichée (par employé et par type pour un
     * magasin, jour par jour pour un employé).
     *
     * @return array{preset: array<string, mixed>, detail: array<string, mixed>}
     */
    public function compute(Store $store, string $from, string $to, ?User $employee = null): array
    {
        $types = $this->wages->activeTypes($store->id);
        $query = Shift::query()->forStore($store)->between($from, $to)->whereNotNull('user_id')->orderBy('shift_date')->orderBy('starts_at');
        if ($employee !== null) {
            $query->where('user_id', $employee->id);
        }

        $rates = [];
        $minutes = [];
        $costs = [];
        $breakdown = [];
        $rows = [];

        foreach ($query->with('shiftType:id,name')->get() as $shift) {
            $uid = (int) $shift->user_id;
            $rates[$uid] ??= $this->wages->personalRates($employee?->id === $uid ? $employee : User::query()->find($uid));
            $wage = $this->wages->costOf($shift, $types, $rates[$uid]);

            $minutes[$uid] = ($minutes[$uid] ?? 0) + $wage->netMinutes;
            $costs[$uid] = ($costs[$uid] ?? 0.0) + $wage->total;

            foreach ($wage->slices as $slice) {
                $key = $slice->shiftTypeId ?? 0;
                $breakdown[$key] ??= ['name' => $slice->name !== '' ? $slice->name : '—', 'minutes' => 0, 'amount' => 0.0];
                $breakdown[$key]['minutes'] += $slice->minutes;
                $breakdown[$key]['amount'] += $slice->amount;
            }

            if ($employee !== null) {
                $rows[] = [
                    'date' => $shift->shift_date->toDateString(),
                    'type' => $shift->shiftType->name ?? '—',
                    'start' => $shift->localStartsAt->format('H:i'),
                    'end' => $shift->localEndsAt->format('H:i'),
                    'gross_min' => $shift->durationMinutes,
                    'pause_min' => $shift->pause_minutes,
                    'net_min' => $wage->netMinutes,
                    'rate' => $wage->rate,
                    'cost' => $wage->total,
                    'has_rate' => $wage->rate > 0,
                ];
            }
        }

        $breakdown = array_values($breakdown);
        usort($breakdown, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        $totalMinutes = array_sum($minutes);
        $totalCost = round(array_sum($costs), 2);
        $hours = $totalMinutes > 0 ? round($totalMinutes / 60, 2) : 0.0;

        ksort($minutes);
        $setting = SalaryReportSetting::forStore($store->id);
        $deductions = $this->deductions($setting, $store->id, $costs);

        $preset = [
            'total_payment' => 0.0,
            'staff_man_hours' => $hours,
            'staff_total_payment' => $totalCost,
            'staff_avg_hourly_wage' => $hours > 0 ? round($totalCost / $hours, 2) : 0.0,
            'active_employees' => count($minutes),
            'employee_hours' => array_map(fn (int $uid, int $mins): array => ['user_id' => $uid, 'minutes' => $mins], array_keys($minutes), array_values($minutes)),
            'income_tax_base' => $totalCost,
            'other_deductions' => $deductions['other'],
            'withholding_tax' => $deductions['income_tax'],
            'residence_tax' => $deductions['resident_tax'],
            'total_deductions' => $deductions['total'],
            'net_payment' => round($totalCost - $deductions['total'], 2),
            // Par défaut, le net est versé par virement ; la part remise en main propre se règle à la main.
            'bank_transfer_salary' => round($totalCost - $deductions['total'], 2),
            'hand_delivered_salary' => 0.0,
        ];

        return ['preset' => $preset, 'detail' => $employee === null
            ? $this->storeDetail($from, $to, $minutes, $costs, $breakdown, $setting)
            : $this->employeeDetail($employee, $from, $to, $rows, (int) ($minutes[$employee->id] ?? 0), $totalCost, $breakdown, $setting)];
    }

    /**
     * Détail jour par jour d'un rapport d'employé, relu sur les shifts du mois du rapport ; null pour un rapport de magasin.
     *
     * @return array<string, mixed>|null
     */
    public function detailFor(SalaryReport $report): ?array
    {
        if ($report->user_id === null || $report->employee === null) {
            return null;
        }

        [$from, $to] = $report->period();

        return $this->compute($report->store, $from, $to, $report->employee)['detail'];
    }

    /**
     * Retenues sur le coût de chaque employé assujetti : une seule fois la taxe résidentielle fixe par employé payé.
     *
     * @param  array<int, float>  $costs  Coût brut par employé.
     * @return array{other: float, income_tax: float, resident_tax: float, total: float}
     */
    private function deductions(SalaryReportSetting $setting, int $storeId, array $costs): array
    {
        $none = ['other' => 0.0, 'income_tax' => 0.0, 'resident_tax' => 0.0, 'total' => 0.0];
        if (! $setting->deductions_enabled) {
            return $none;
        }

        $subject = SalaryReportMember::query()->where('store_id', $storeId)->where('subject_to_deductions', true)->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
        $other = 0.0;
        $incomeTax = 0.0;
        $residentTax = 0.0;

        foreach ($costs as $uid => $cost) {
            if ($cost <= 0 || ! in_array($uid, $subject, true)) {
                continue;
            }

            foreach (['health_insurance_rate', 'pension_rate', 'employment_insurance_rate'] as $rate) {
                $other += round($cost * (float) $setting->{$rate} / 100, 2);
            }
            $incomeTax += round($cost * (float) $setting->income_tax_rate / 100, 2);
            $residentTax += (float) $setting->resident_tax_monthly;
        }

        $other = round($other, 2);
        $incomeTax = round($incomeTax, 2);
        $residentTax = round($residentTax, 2);

        return ['other' => $other, 'income_tax' => $incomeTax, 'resident_tax' => $residentTax, 'total' => round($other + $incomeTax + $residentTax, 2)];
    }

    /**
     * @param  array<int, int>  $minutes
     * @param  array<int, float>  $costs
     * @param  list<array{name: string, minutes: int, amount: float}>  $breakdown
     * @return array<string, mixed>
     */
    private function storeDetail(string $from, string $to, array $minutes, array $costs, array $breakdown, SalaryReportSetting $setting): array
    {
        $names = User::query()->whereIn('id', array_keys($minutes))->pluck('name', 'id');
        $employees = [];
        foreach ($minutes as $uid => $mins) {
            $employees[] = ['user_id' => $uid, 'name' => (string) ($names[$uid] ?? '#'.$uid), 'hours' => round($mins / 60, 2), 'cost' => round($costs[$uid] ?? 0.0, 2)];
        }
        usort($employees, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return [
            'mode' => 'store', 'from' => $from, 'to' => $to, 'employees' => $employees, 'type_breakdown' => $breakdown,
            'deductions' => $this->settingLines($setting),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array{name: string, minutes: int, amount: float}>  $breakdown
     * @return array<string, mixed>
     */
    private function employeeDetail(User $employee, string $from, string $to, array $rows, int $minutes, float $cost, array $breakdown, SalaryReportSetting $setting): array
    {
        $subject = SalaryReportMember::query()->where('store_id', $setting->store_id)->where('user_id', $employee->id)->where('subject_to_deductions', true)->exists();
        $applied = [];
        $total = 0.0;

        if ($setting->deductions_enabled && $subject) {
            foreach (['health_insurance_rate' => 'Health insurance', 'pension_rate' => 'Pension', 'employment_insurance_rate' => 'Employment insurance', 'income_tax_rate' => 'Income tax'] as $rate => $label) {
                if ((float) $setting->{$rate} > 0) {
                    $amount = round($cost * (float) $setting->{$rate} / 100, 2);
                    $applied[] = ['label' => $label, 'rate' => (float) $setting->{$rate}, 'amount' => $amount, 'flat' => false];
                    $total += $amount;
                }
            }
            if ((float) $setting->resident_tax_monthly > 0 && $cost > 0) {
                $applied[] = ['label' => 'Resident tax', 'rate' => null, 'amount' => (float) $setting->resident_tax_monthly, 'flat' => true];
                $total += (float) $setting->resident_tax_monthly;
            }
        }

        return [
            'mode' => 'employee', 'from' => $from, 'to' => $to, 'employee' => $employee->name, 'rows' => $rows,
            'net_minutes' => $minutes, 'gross_pay' => round($cost, 2), 'type_breakdown' => $breakdown,
            'deductions_applied' => $setting->deductions_enabled && $subject, 'deductions' => $applied,
            'total_deductions' => round($total, 2), 'net_pay' => round($cost - $total, 2),
            'any_rate' => array_filter($rows, fn (array $row): bool => $row['has_rate']) !== [],
        ];
    }

    /**
     * @return list<array{label: string, value: float}>
     */
    private function settingLines(SalaryReportSetting $setting): array
    {
        if (! $setting->deductions_enabled) {
            return [];
        }

        return [
            ['label' => 'Health insurance', 'value' => (float) $setting->health_insurance_rate],
            ['label' => 'Pension', 'value' => (float) $setting->pension_rate],
            ['label' => 'Employment insurance', 'value' => (float) $setting->employment_insurance_rate],
            ['label' => 'Income tax', 'value' => (float) $setting->income_tax_rate],
            ['label' => 'Resident tax', 'value' => (float) $setting->resident_tax_monthly],
        ];
    }
}
