<?php

namespace Kintai\Bundles\Analytics;

use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Statistiques d'un employé : fiche d'un employé dans un magasin sur une période (`employeeStats`) et vue mensuelle de ses
 * propres shifts, tous magasins confondus, avec le salaire estimé (`calculate`). Les montants sont bruts (nombres) : la
 * mise en forme (devise, libellés de dates) est celle de la page.
 */
class EmployeeStatsService
{
    public function __construct(
        private readonly StoreShifts $shifts,
        private readonly ApprovedAbsences $absences,
        private readonly AcceptedSwaps $swaps,
    ) {}

    /**
     * Fiche d'un employé dans un magasin : heures, coût, jours travaillés, absences approuvées, série la plus longue,
     * heures des 12 derniers mois, par type et par jour de la semaine, 15 derniers shifts.
     *
     * `swapCount` : échanges acceptés de la période où l'employé est demandeur ou collègue sollicité (contrat `ShiftSwapStatistics`).
     *
     * @return array<string, mixed>
     */
    public function employeeStats(int $storeId, int $userId, int $period): array
    {
        $today = $this->today();
        $todayDate = $today->toDateString();
        $since = $today->subDays($period)->toDateString();
        $since12m = $today->subDays(365)->toDateString();

        $facts = $this->shifts->between($storeId, min($since, $since12m), $todayDate, $userId);
        $periodShifts = array_values(array_filter($facts, fn (ShiftFact $s): bool => $s->date >= $since));
        $types = $this->shifts->typesOf($storeId);

        $grossMin = 0;
        $netMin = 0;
        $cost = 0.0;
        $anyRate = false;
        $workDays = [];
        foreach ($periodShifts as $s) {
            $grossMin += $s->duration;
            $netMin += $s->wage->netMinutes;
            $cost += $s->wage->total;
            $workDays[$s->date] = true;
            $anyRate = $anyRate || $s->wage->rate > 0;
        }

        $absDays = array_sum(array_column($this->absences->startingFrom($storeId, [$userId], $since), 'days'));

        $monthlyHours = [];
        $firstMonth = $today->startOfMonth()->subMonths(11);
        for ($i = 0; $i < 12; $i++) {
            $monthlyHours[$firstMonth->addMonths($i)->format('Y-m')] = 0.0;
        }
        foreach ($facts as $s) {
            $month = substr($s->date, 0, 7);
            if ($s->date >= $since12m && isset($monthlyHours[$month])) {
                $monthlyHours[$month] += $s->plainNetMinutes() / 60;
            }
        }

        $typeHours = [];
        $dowBuckets = array_fill(0, 7, 0.0);
        foreach ($periodShifts as $s) {
            $label = $s->typeId !== null ? (isset($types[$s->typeId]) ? $types[$s->typeId]->name : __('Type :id', ['id' => $s->typeId])) : __('No type');
            $typeHours[$label] = ($typeHours[$label] ?? 0.0) + $s->plainNetMinutes() / 60;
            $dowBuckets[StatsMath::isoDay($s->date) % 7] += $s->plainNetMinutes() / 60;
        }
        arsort($typeHours);

        $recentShifts = Shift::query()
            ->where('store_id', $storeId)
            ->where('user_id', $userId)
            ->where('shift_date', '<=', $todayDate)
            ->orderByDesc('shift_date')
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->limit(15)
            ->get()
            ->all();

        return [
            'since' => $since,
            'today' => $todayDate,
            'period' => $period,
            'storeTypesMap' => $types,
            'totalShifts' => count($periodShifts),
            'grossHours' => round($grossMin / 60, 1),
            'netHours' => round($netMin / 60, 1),
            'cost' => round($cost, 2),
            'anyRate' => $anyRate,
            'workDays' => count($workDays),
            'absDays' => $absDays,
            'maxConsec' => StatsMath::consecutiveRuns(array_map('strval', array_keys($workDays)))['max'],
            'swapCount' => $this->swaps->countByUser($storeId, $since, $todayDate)[$userId] ?? 0,
            'monthlyHours' => $monthlyHours,
            'typeHours' => $typeHours,
            'dowChart' => array_combine(array_map(fn (string $day): string => __($day), ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']), $dowBuckets),
            'recentShifts' => $recentShifts,
        ];
    }

    /**
     * Mois d'un employé (« AAAA-MM », le mois courant si le format est invalide) : heures, heures par semaine, salaire
     * estimé et détail des shifts du mois, dans tous ses magasins. La devise est celle de son premier magasin (JPY sans
     * magasin).
     *
     * @return array<string, mixed>
     */
    public function calculate(int $userId, string $month = ''): array
    {
        $now = CarbonImmutable::now($this->timezone());
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            $month = $now->format('Y-m');
        }

        $start = CarbonImmutable::parse($month.'-01');
        $end = $start->endOfMonth();

        $weeks = [];
        for ($day = $start; $day <= $end; $day = $day->addDay()) {
            $weeks[$day->format('o-W')] = true;
        }

        // Tous les types : un shift garde son coût même si son type n'est plus proposé.
        $types = ShiftType::query()->orderBy('sort_order')->orderBy('id')->get();
        $typeNames = $types->pluck('name', 'id');
        $shifts = Shift::query()
            ->where('user_id', $userId)
            ->whereBetween('shift_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('shift_date')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        $minutes = 0;
        $pay = 0.0;
        $hasRate = false;
        $details = [];
        foreach ($this->shifts->facts($shifts, $types) as $fact) {
            $minutes += $fact->wage->netMinutes;
            $pay += $fact->wage->total;
            $hasRate = $hasRate || $fact->wage->rate > 0;

            $details[] = [
                'date' => $fact->date,
                'start' => substr($fact->startTime, 0, 5),
                'end' => substr($fact->endTime, 0, 5),
                'type_name' => $fact->typeId !== null ? ($typeNames[$fact->typeId] ?? '—') : '—',
                'net_min' => $fact->wage->netMinutes,
                'pause_min' => $fact->pause,
                'rate' => $fact->wage->rate,
                'pay' => $fact->wage->total,
                'has_rate' => $fact->wage->rate > 0,
            ];
        }

        $storeId = DB::table('store_user')->where('user_id', $userId)->orderBy('id')->value('store_id');
        $store = $storeId === null ? null : Store::query()->find($storeId);

        return [
            'hours_month' => $minutes / 60,
            'hours_week' => ($minutes / 60) / max(1, count($weeks)),
            'estimated_pay' => $pay,
            'has_rate' => $hasRate,
            'month_key' => $month,
            'prev_month' => $start->subMonth()->format('Y-m'),
            'next_month' => $start->addMonth()->format('Y-m'),
            'is_current' => $month === $now->format('Y-m'),
            'currency' => strtoupper(trim($store->currency ?? 'JPY')),
            'currency_symbol_style' => $store->currency_symbol_style ?? 'kanji',
            'shift_details' => $details,
        ];
    }

    private function timezone(): string
    {
        return (string) config('kintai.timezone', 'UTC');
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone())->startOfDay();
    }
}
