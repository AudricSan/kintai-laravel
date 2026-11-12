<?php

namespace Kintai\Bundles\Analytics;

use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Kintai\Contracts\Planning\TimeOffRequestSummary;

/**
 * Statistiques d'un magasin : répartition des heures, coût, équité, stabilité du planning, absences, risque d'épuisement,
 * comparaison avec la période précédente ; rapport par employé ; comparaison multi-magasins.
 *
 * Les périodes s'expriment en jours jusqu'à aujourd'hui (jour local de l'instance). Les résultats de `storeStats()` et de
 * `costByMonth()` sont gardés dans le cache `array` de Laravel, comme le service d'origine les gardait pour la requête :
 * le tableau de bord demande les mêmes statistiques à plusieurs widgets. Clé : magasin, période, bornes de durée et jour ;
 * pas d'invalidation (la durée de vie d'une requête) ; 60 secondes au plus dans un processus qui vit plus longtemps.
 */
class StoreStatsService
{
    public const CACHE_SECONDS = 60;

    private const CACHE_STORE = 'array';

    public function __construct(
        private readonly StoreShifts $shifts,
        private readonly ApprovedAbsences $absences,
        private readonly AcceptedSwaps $swaps,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function storeStats(int $storeId, int $period, int $minShiftMin = 0, int $maxShiftMin = 0): array
    {
        $key = "analytics:stats:{$storeId}:{$period}:{$minShiftMin}:{$maxShiftMin}:".$this->today()->toDateString();

        return $this->remember($key, fn (): array => $this->computeStoreStats($storeId, $period, $minShiftMin, $maxShiftMin));
    }

    /**
     * Coût salarial par mois (« AAAA-MM » => montant) sur les `$period` derniers jours : exactement
     * `storeStats($storeId, $period)['costByMonth']`, sans calculer le reste des statistiques.
     *
     * @return array<string, float>
     */
    public function costByMonth(int $storeId, int $period): array
    {
        $key = "analytics:cost:{$storeId}:{$period}:".$this->today()->toDateString();

        return $this->remember($key, function () use ($storeId, $period): array {
            $today = $this->today();
            $costByMonth = [];
            foreach ($this->shifts->between($storeId, $today->subDays($period)->toDateString(), $today->toDateString()) as $shift) {
                $month = substr($shift->date, 0, 7);
                $costByMonth[$month] = ($costByMonth[$month] ?? 0) + $shift->wage->total;
            }
            ksort($costByMonth);

            return $costByMonth;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function computeStoreStats(int $storeId, int $period, int $minShiftMin, int $maxShiftMin): array
    {
        $today = $this->today();
        $todayDate = $today->toDateString();
        $since = $today->subDays($period)->toDateString();
        $prevSince = $today->subDays(2 * $period)->toDateString();

        // Une seule lecture : période précédente + période courante, découpées en mémoire.
        $window = $this->shifts->between($storeId, $prevSince, $todayDate);
        $shifts = array_values(array_filter($window, fn (ShiftFact $s): bool => $s->date >= $since));
        $n = count($shifts);

        $memberIds = array_map(fn (array $m): int => $m['user_id'], $this->shifts->memberships($storeId));
        $types = $this->shifts->typesOf($storeId);
        $typeLabel = fn (?int $id): string => $id !== null && isset($types[$id]) ? $types[$id]->name : __('Not defined');

        $durations = array_map(fn (ShiftFact $s): int => $s->duration, $shifts);
        $avgDuration = $n ? array_sum($durations) / $n / 60 : 0;
        $totalGrossHours = array_sum($durations) / 60;
        $totalNetHours = array_sum(array_map(fn (ShiftFact $s): int => $s->plainNetMinutes(), $shifts)) / 60;

        $shiftsByUser = [];
        $daysByUser = [];
        foreach ($shifts as $s) {
            $shiftsByUser[$s->userId][] = $s;
            $daysByUser[$s->userId][$s->date] = true;
        }
        $avgShiftsPerEmployee = $shiftsByUser ? $n / count($shiftsByUser) : 0;
        $avgDaysPerEmployee = $daysByUser ? array_sum(array_map('count', $daysByUser)) / count($daysByUser) : 0;

        $openingShifts = count(array_filter($shifts, fn (ShiftFact $s): bool => $s->startTime < '12:00:00'));

        $gaps = [];
        foreach ($shiftsByUser as $userShifts) {
            usort($userShifts, fn (ShiftFact $a, ShiftFact $b): int => $a->startsAt <=> $b->startsAt);
            for ($i = 1; $i < count($userShifts); $i++) {
                $gap = $userShifts[$i]->startsAt - $userShifts[$i - 1]->endsAt;
                if ($gap > 0) {
                    $gaps[] = $gap / 3600;
                }
            }
        }

        $activeDays = count(array_unique(array_map(fn (ShiftFact $s): string => $s->date, $shifts)));

        $hoursBySlot = array_fill(0, 24, 0);
        foreach ($shifts as $s) {
            $startHour = (int) substr($s->startTime, 0, 2);
            $endHour = (int) substr($s->endTime, 0, 2);
            if (! $s->crossesMidnight) {
                for ($h = $startHour; $h < $endHour; $h++) {
                    $hoursBySlot[$h]++;
                }
            } else {
                for ($h = $startHour; $h < 24; $h++) {
                    $hoursBySlot[$h]++;
                }
                for ($h = 0; $h < $endHour; $h++) {
                    $hoursBySlot[$h]++;
                }
            }
        }
        $avgEmpPerHour = $activeDays > 0 ? array_sum($hoursBySlot) / $activeDays / 24 : 0;

        $hoursByUser = [];
        $hoursByType = [];
        foreach ($shifts as $s) {
            $hoursByUser[$s->userId] = ($hoursByUser[$s->userId] ?? 0) + $s->plainNetMinutes() / 60;
            $label = $typeLabel($s->typeId);
            $hoursByType[$label] = ($hoursByType[$label] ?? 0) + $s->plainNetMinutes() / 60;
        }
        arsort($hoursByType);

        $hours = array_values($hoursByUser);
        $hoursN = count($hours);
        $meanHours = $hoursN ? array_sum($hours) / $hoursN : 0;
        $variance = $hoursN > 1 ? array_sum(array_map(fn ($h): float => ($h - $meanHours) ** 2, $hours)) / $hoursN : 0;
        $gini = StatsMath::gini($hours);

        arsort($hoursByUser);
        $top20count = max(1, (int) ceil($hoursN * 0.2));
        $top20ratio = array_sum($hoursByUser) > 0
            ? round(array_sum(array_slice($hoursByUser, 0, $top20count)) / array_sum($hoursByUser) * 100, 1) : 0;

        $modified = array_values(array_filter($shifts, fn (ShiftFact $s): bool => $s->updatedAt !== null));
        $modRate = $n ? round(count($modified) / $n * 100, 1) : 0;
        $modDelays = [];
        $modByDow = array_fill(1, 7, 0);
        $modByManager = [];
        $shiftsCreatedByManager = [];
        foreach ($shifts as $s) {
            $shiftsCreatedByManager[$s->createdBy] = ($shiftsCreatedByManager[$s->createdBy] ?? 0) + 1;
        }
        foreach ($modified as $s) {
            $delay = (int) $s->updatedAt - $s->createdAt;
            if ($delay > 0) {
                $modDelays[] = $delay / 3600;
            }
            $modByDow[$this->localIsoDay((int) $s->updatedAt)]++;
            $modByManager[$s->createdBy] = ($modByManager[$s->createdBy] ?? 0) + 1;
        }
        arsort($shiftsCreatedByManager);
        arsort($modByManager);

        // Toutes les demandes par statut et par type ; jours d'absence et demandes par employé sur les congés approuvés seulement.
        $timeoffsByStatus = array_fill_keys(TimeOffRequestSummary::STATUSES, 0);
        $timeoffsByType = [];
        $timeoffsByUser = [];
        $approvedDays = 0;
        foreach ($this->absences->requestsStartingFrom($storeId, $memberIds, $since) as $request) {
            $timeoffsByStatus[$request->status] = ($timeoffsByStatus[$request->status] ?? 0) + 1;
            $timeoffsByType[$request->type] = ($timeoffsByType[$request->type] ?? 0) + 1;

            if ($request->status === TimeOffRequestSummary::APPROVED) {
                $timeoffsByUser[$request->userId] = ($timeoffsByUser[$request->userId] ?? 0) + 1;
                $approvedDays += StatsMath::inclusiveDays($request->startDate, $request->endDate);
            }
        }
        arsort($timeoffsByType);
        $absRate = (count($memberIds) > 0 && $period > 0) ? round($approvedDays / (count($memberIds) * $period) * 100, 2) : 0;

        $totalCost = 0.0;
        $costByType = [];
        $costByUser = [];
        $costByMonth = [];
        $hoursByMonth = [];
        $hoursByDow = array_fill(1, 7, 0.0);
        $shiftsByDow = array_fill(1, 7, 0);
        $costByDow = array_fill(1, 7, 0.0);
        $hoursByWeek = [];
        foreach ($shifts as $s) {
            $cost = $s->wage->total;
            $h = $s->wage->netMinutes / 60;
            $totalCost += $cost;
            $label = $typeLabel($s->typeId);
            $month = substr($s->date, 0, 7);
            $costByType[$label] = ($costByType[$label] ?? 0) + $cost;
            $costByUser[$s->userId] = ($costByUser[$s->userId] ?? 0) + $cost;
            $costByMonth[$month] = ($costByMonth[$month] ?? 0) + $cost;
            $hoursByMonth[$month] = ($hoursByMonth[$month] ?? 0) + $h;

            $dow = StatsMath::isoDay($s->date);
            $hoursByDow[$dow] += $h;
            $shiftsByDow[$dow]++;
            $costByDow[$dow] += $cost;
            $week = CarbonImmutable::parse($s->date)->format('o-W');
            $hoursByWeek[$week] = ($hoursByWeek[$week] ?? 0) + $h;
        }
        ksort($costByMonth);
        ksort($hoursByMonth);
        ksort($hoursByWeek);
        arsort($costByType);

        $conflictsCount = 0;
        $shortRestCount = 0;
        $maxConsecDays = 0;
        $consecSum = 0;
        $consecCount = 0;
        foreach ($shiftsByUser as $userId => $userShifts) {
            usort($userShifts, fn (ShiftFact $a, ShiftFact $b): int => $a->startsAt <=> $b->startsAt);
            for ($i = 1; $i < count($userShifts); $i++) {
                $previousEnd = $userShifts[$i - 1]->endsAt;
                $currentStart = $userShifts[$i]->startsAt;
                if ($currentStart < $previousEnd) {
                    $conflictsCount++;
                } elseif (($currentStart - $previousEnd) < 11 * 3600) {
                    $shortRestCount++;
                }
            }
            $runs = StatsMath::consecutiveRuns(array_map('strval', array_keys($daysByUser[$userId])));
            $maxConsecDays = max($maxConsecDays, $runs['max']);
            $consecSum += array_sum($runs['runs']);
            $consecCount += count($runs['runs']);
        }

        $equityScore = round((1 - $gini) * 100);
        $efficiencyScore = $totalGrossHours > 0 ? round($totalNetHours / $totalGrossHours * 100) : 100;
        $stabilityScore = round(max(0, 100 - $modRate));
        $maxHours = $hours ? max($hours) : 0;
        $burnoutRisk = 0;
        if ($maxConsecDays > 6) {
            $burnoutRisk += 30;
        } elseif ($maxConsecDays > 4) {
            $burnoutRisk += 15;
        }
        $burnoutRisk += min(30, $shortRestCount * 5);
        $burnoutRisk += $maxHours > 50 ? 20 : ($maxHours > 40 ? 10 : 0);
        $burnoutRisk = min(100, $burnoutRisk);

        $previousEnd = CarbonImmutable::parse($since)->subDay()->toDateString();
        $previous = array_values(array_filter($window, fn (ShiftFact $s): bool => $s->date >= $prevSince && $s->date <= $previousEnd));
        $prevN = count($previous);
        $prevHours = array_sum(array_map(fn (ShiftFact $s): float => $s->plainNetMinutes() / 60, $previous));
        $prevModRate = $prevN > 0
            ? round(count(array_filter($previous, fn (ShiftFact $s): bool => $s->updatedAt !== null)) / $prevN * 100, 1) : 0;
        $delta = [
            'n' => $prevN > 0 ? round(($n - $prevN) / $prevN * 100, 1) : null,
            'totalNetHours' => $prevHours > 0 ? round(($totalNetHours - $prevHours) / $prevHours * 100, 1) : null,
            'modRate' => $prevModRate > 0 ? round($modRate - $prevModRate, 1) : null,
        ];

        return [
            'since' => $since,
            'today' => $todayDate,
            'period' => $period,
            'n' => $n,
            'usersMap' => $this->usersMap([...$memberIds, ...array_keys($shiftsByUser), ...array_keys($shiftsCreatedByManager)]),
            'memberIds' => $memberIds,
            'avgDuration' => round($avgDuration, 2),
            'distShort' => count(array_filter($durations, fn (int $d): bool => $d < 240)),
            'distMedium' => count(array_filter($durations, fn (int $d): bool => $d >= 240 && $d <= 480)),
            'distLong' => count(array_filter($durations, fn (int $d): bool => $d > 480)),
            'avgShiftsPerEmployee' => round($avgShiftsPerEmployee, 1),
            'avgDaysPerEmployee' => round($avgDaysPerEmployee, 1),
            'openingShifts' => $openingShifts,
            'closingShifts' => $n - $openingShifts,
            'shortRateShifts' => $minShiftMin > 0 ? count(array_filter($durations, fn (int $d): bool => $d < $minShiftMin)) : null,
            'longRateShifts' => $maxShiftMin > 0 ? count(array_filter($durations, fn (int $d): bool => $d > $maxShiftMin)) : null,
            'avgTimeBetweenShifts' => $gaps ? round(array_sum($gaps) / count($gaps), 1) : null,
            'totalNetHours' => round($totalNetHours, 1),
            'totalGrossHours' => round($totalGrossHours, 1),
            'avgEmpPerHour' => round($avgEmpPerHour, 2),
            'hoursBySlot' => $hoursBySlot,
            'activeDays' => $activeDays,
            'hoursByUser' => $hoursByUser,
            'meanHours' => round($meanHours, 1),
            'stdDev' => round(sqrt($variance), 1),
            'gini' => round($gini, 3),
            'top20ratio' => $top20ratio,
            'hoursByType' => $hoursByType,
            'modRate' => $modRate,
            'avgModDelay' => $modDelays ? round(array_sum($modDelays) / count($modDelays), 1) : null,
            'modByDow' => $modByDow,
            'shiftsCreatedByManager' => $shiftsCreatedByManager,
            'modByManager' => $modByManager,
            'timeoffsByStatus' => $timeoffsByStatus,
            'timeoffsByType' => $timeoffsByType,
            'timeoffsByUser' => $timeoffsByUser,
            'absRate' => $absRate,
            'approvedDays' => $approvedDays,
            'totalCost' => round($totalCost, 2),
            'avgCostPerShift' => $n ? round($totalCost / $n, 2) : 0,
            'avgCostPerHour' => $totalNetHours > 0 ? round($totalCost / $totalNetHours, 2) : 0,
            'costByType' => $costByType,
            'costByUser' => $costByUser,
            'costByMonth' => $costByMonth,
            'hoursByMonth' => $hoursByMonth,
            'hoursByDow' => $hoursByDow,
            'shiftsByDow' => $shiftsByDow,
            'costByDow' => $costByDow,
            'hoursByWeek' => $hoursByWeek,
            'dowLabels' => array_map(fn (string $day): string => __($day), ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']),
            'conflictsCount' => $conflictsCount,
            'maxConsecDays' => $maxConsecDays,
            'avgConsecDays' => $consecCount ? round($consecSum / $consecCount, 1) : 0,
            'shortRestCount' => $shortRestCount,
            'equityScore' => $equityScore,
            'efficiencyScore' => $efficiencyScore,
            'stabilityScore' => $stabilityScore,
            'burnoutRisk' => $burnoutRisk,
            'delta' => $delta,
        ];
    }

    /**
     * Données d'export des statistiques d'un magasin (sans les calculs de comparaison ni d'absences). La période finit
     * aujourd'hui, ou à `$until` (jour local, borne incluse).
     *
     * @return array<string, mixed>
     */
    public function storeStatsExportData(int $storeId, int $period, ?CarbonImmutable $until = null): array
    {
        $today = $until?->startOfDay() ?? $this->today();
        $since = $today->subDays($period)->toDateString();
        $shifts = $this->shifts->between($storeId, $since, $today->toDateString());
        $n = count($shifts);

        $memberIds = array_map(fn (array $m): int => $m['user_id'], $this->shifts->memberships($storeId));
        $types = $this->shifts->typesOf($storeId);

        $durations = array_map(fn (ShiftFact $s): int => $s->duration, $shifts);
        $totalGrossHours = array_sum($durations) / 60;
        $totalNetHours = 0.0;
        $hoursByUser = [];
        $costByUser = [];
        $hoursByType = [];
        $hoursByMonth = [];
        $costByMonth = [];
        $hoursByWeek = [];

        foreach ($shifts as $s) {
            $h = $s->wage->netMinutes / 60;
            $label = $s->typeId !== null && isset($types[$s->typeId]) ? $types[$s->typeId]->name : __('Not defined');
            $month = substr($s->date, 0, 7);
            $week = CarbonImmutable::parse($s->date)->format('o-W');

            $totalNetHours += $h;
            $hoursByUser[$s->userId] = ($hoursByUser[$s->userId] ?? 0) + $h;
            $costByUser[$s->userId] = ($costByUser[$s->userId] ?? 0) + $s->wage->total;
            $hoursByType[$label] = ($hoursByType[$label] ?? 0) + $h;
            $hoursByMonth[$month] = ($hoursByMonth[$month] ?? 0) + $h;
            $costByMonth[$month] = ($costByMonth[$month] ?? 0) + $s->wage->total;
            $hoursByWeek[$week] = ($hoursByWeek[$week] ?? 0) + $h;
        }
        ksort($hoursByMonth);
        ksort($costByMonth);
        ksort($hoursByWeek);
        arsort($hoursByUser);
        arsort($hoursByType);

        $modRate = $n ? round(count(array_filter($shifts, fn (ShiftFact $s): bool => $s->updatedAt !== null)) / $n * 100, 1) : 0;
        $gini = StatsMath::gini(array_values($hoursByUser));

        return [
            'since' => $since,
            'today' => $today->toDateString(),
            'period' => $period,
            'n' => $n,
            'usersMap' => $this->usersMap([...$memberIds, ...array_keys($hoursByUser)]),
            'memberIds' => $memberIds,
            'avgDuration' => $n ? array_sum($durations) / $n / 60 : 0,
            'totalNetHours' => $totalNetHours,
            'totalGrossHours' => $totalGrossHours,
            'activeDays' => count(array_unique(array_map(fn (ShiftFact $s): string => $s->date, $shifts))),
            'hoursByUser' => $hoursByUser,
            'costByUser' => $costByUser,
            'hoursByType' => $hoursByType,
            'hoursByMonth' => $hoursByMonth,
            'costByMonth' => $costByMonth,
            'hoursByWeek' => $hoursByWeek,
            'modRate' => $modRate,
            'equityScore' => round((1 - $gini) * 100),
            'efficiencyScore' => $totalGrossHours > 0 ? round($totalNetHours / $totalGrossHours * 100) : 100,
            'stabilityScore' => round(max(0, 100 - $modRate)),
        ];
    }

    /**
     * Rapport par employé d'un magasin : shifts, heures, coût, jours travaillés, absences, série la plus longue, trié par
     * heures nettes décroissantes ; tous les employés du magasin y figurent, même sans shift sur la période.
     *
     * `swaps` : échanges acceptés de la période où l'employé est demandeur ou collègue sollicité (contrat `ShiftSwapStatistics`).
     *
     * @return array<string, mixed>
     */
    public function employeeReport(int $storeId, int $period): array
    {
        $today = $this->today();
        $since = $today->subDays($period)->toDateString();
        $shifts = $this->shifts->between($storeId, $since, $today->toDateString());

        $members = $this->shifts->memberships($storeId);
        $memberIds = array_map(fn (array $m): int => $m['user_id'], $members);
        $roles = $this->storeRoles($storeId);
        $membersMap = [];
        foreach ($members as $member) {
            $role = $roles[$member['user_id']] ?? null;
            $membersMap[$member['user_id']] = $member + [
                'role_name' => $role['name'] ?? '—',
                'role_is_managing' => $role['is_managing'] ?? false,
            ];
        }

        $absenceDays = [];
        foreach ($this->absences->startingFrom($storeId, $memberIds, $since) as $absence) {
            $absenceDays[$absence['user_id']] = ($absenceDays[$absence['user_id']] ?? 0) + $absence['days'];
        }

        $swapCounts = $this->swaps->countByUser($storeId, $since, $today->toDateString());

        $rates = $this->shifts->personalRates($memberIds);
        $shiftsByUser = [];
        foreach ($shifts as $s) {
            $shiftsByUser[$s->userId][] = $s;
        }

        $employeeStats = [];
        foreach ($memberIds as $userId) {
            $userShifts = $shiftsByUser[$userId] ?? [];
            $grossMin = 0;
            $netMin = 0;
            $cost = 0.0;
            $hasRate = ($rates[$userId] ?? []) !== [];
            $days = [];
            foreach ($userShifts as $s) {
                $grossMin += $s->duration;
                $netMin += $s->wage->netMinutes;
                $cost += $s->wage->total;
                $days[$s->date] = true;
                $hasRate = $hasRate || $s->wage->rate > 0;
            }

            $employeeStats[$userId] = [
                'shifts' => count($userShifts),
                'gross_hours' => round($grossMin / 60, 1),
                'net_hours' => round($netMin / 60, 1),
                'cost' => round($cost, 2),
                'work_days' => count($days),
                'abs_days' => $absenceDays[$userId] ?? 0,
                'swaps' => $swapCounts[$userId] ?? 0,
                'max_consec' => StatsMath::consecutiveRuns(array_map('strval', array_keys($days)))['max'],
                'has_rate' => $hasRate,
            ];
        }

        uasort($employeeStats, fn (array $a, array $b): int => $b['net_hours'] <=> $a['net_hours']);

        return [
            'since' => $since,
            'today' => $today->toDateString(),
            'period' => $period,
            'usersMap' => $this->usersMap($memberIds),
            'membersMap' => $membersMap,
            'memberIds' => $memberIds,
            'employeeStats' => $employeeStats,
            'storeTypesMap' => $this->shifts->typesOf($storeId),
            'totalShifts' => array_sum(array_column($employeeStats, 'shifts')),
            'totalNetHours' => round(array_sum(array_column($employeeStats, 'net_hours')), 1),
            'totalGrossHours' => round(array_sum(array_column($employeeStats, 'gross_hours')), 1),
            'totalCost' => round(array_sum(array_column($employeeStats, 'cost')), 2),
            'totalAbsDays' => array_sum(array_column($employeeStats, 'abs_days')),
            'activeCount' => count(array_filter($employeeStats, fn (array $e): bool => $e['shifts'] > 0)),
            'anyHasRate' => array_filter($employeeStats, fn (array $e): bool => $e['has_rate']) !== [],
            'anySwaps' => array_filter($employeeStats, fn (array $e): bool => $e['swaps'] > 0) !== [],
        ];
    }

    /**
     * Comparaison des indicateurs clés des magasins donnés (un magasin inconnu est ignoré).
     *
     * @param  list<int>  $storeIds
     * @return list<array<string, mixed>>
     */
    public function multiStoreComparison(array $storeIds, int $period): array
    {
        $stores = Store::query()->whereIn('id', $storeIds)->get()->keyBy('id');
        $rows = [];

        foreach ($storeIds as $storeId) {
            $store = $stores->get($storeId);
            if ($store === null) {
                continue;
            }

            $stats = $this->storeStats($storeId, $period);
            $rows[] = [
                'store_id' => $storeId,
                'store_name' => $store->name,
                'currency' => $store->currency,
                'currency_symbol_style' => $store->currency_symbol_style,
                'total_shifts' => $stats['n'],
                'total_hours' => $stats['totalNetHours'],
                'avg_hourly' => $stats['avgCostPerHour'],
                'total_cost' => $stats['totalCost'],
                'employee_count' => count($stats['memberIds']),
                'stability' => $stats['stabilityScore'],
                'efficiency' => $stats['efficiencyScore'],
                'equity' => $stats['equityScore'],
            ];
        }

        return $rows;
    }

    /**
     * Comparaison limitée aux magasins que l'utilisateur voit (`payroll.view` avec sa portée magasin).
     *
     * @return list<array<string, mixed>>
     */
    public function comparisonFor(User $viewer, int $period): array
    {
        return $this->multiStoreComparison(app(AnalyticsAccess::class)->viewableStoreIds($viewer), $period);
    }

    /**
     * Rôle de chaque employé dans le magasin : utilisateur => nom et caractère « encadrant » du rôle.
     *
     * @return array<int, array{name: string, is_managing: bool}>
     */
    private function storeRoles(int $storeId): array
    {
        $roles = [];
        $rows = DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->where('role_user.store_id', $storeId)
            ->orderBy('role_user.id')
            ->get(['role_user.user_id', 'roles.name', 'roles.is_manager']);
        foreach ($rows as $row) {
            $roles[(int) $row->user_id] ??= ['name' => (string) $row->name, 'is_managing' => (bool) $row->is_manager];
        }

        return $roles;
    }

    /**
     * Utilisateurs par identifiant, sans aucun champ secret (mot de passe, jetons, double authentification).
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function usersMap(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }

        $map = [];
        $users = User::withTrashed()->whereIn('id', $ids)
            ->get(['id', 'name', 'first_name', 'last_name', 'email', 'employee_code', 'color', 'is_active']);
        foreach ($users as $user) {
            $map[$user->id] = $user->toArray();
        }

        return $map;
    }

    private function localIsoDay(int $timestamp): int
    {
        return CarbonImmutable::createFromTimestampUTC($timestamp)->setTimezone($this->timezone())->dayOfWeekIso;
    }

    private function timezone(): string
    {
        return (string) config('kintai.timezone', 'UTC');
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone())->startOfDay();
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $compute
     * @return T
     */
    private function remember(string $key, Closure $compute): mixed
    {
        return Cache::store(self::CACHE_STORE)->remember($key, self::CACHE_SECONDS, $compute);
    }
}
