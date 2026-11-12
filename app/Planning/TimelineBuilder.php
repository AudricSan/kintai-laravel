<?php

namespace App\Planning;

use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Données de la frise d'un magasin : par jour, une ligne par employé membre actif (plus les employés qui ont
 * un shift sans être membres actifs) et une ligne « shifts ouverts », barres positionnées, effectif par heure
 * et totaux. Partagé par la page de la frise et par sa version imprimable.
 *
 * @phpstan-type Bar array{id: int, user_id: int|null, left: float, width: float, start: string, end: string, startMinute: int, duration: int, net: int, color: string, type: string|null, cutEnd: bool, lane: int, cost: float|null, notes: string|null}
 * @phpstan-type Row array{user: User|null, inactive: bool, bars: list<Bar>, lanes: int, net: int, cost: float|null}
 * @phpstan-type Day array{date: CarbonImmutable, rows: list<Row>, staff: int, shifts: int, understaffed: bool, hourly: list<int>, lowHours: list<bool>, understaffedHours: list<bool>, net: int, cost: float|null}
 */
class TimelineBuilder
{
    public function __construct(private readonly ShiftWageCalculator $calculator) {}

    /**
     * @return list<Day>
     */
    public function build(Store $store, CarbonImmutable $firstDay, int $numDays, bool $withCost): array
    {
        $lastDay = $firstDay->addDays($numDays - 1);

        $members = $store->members()
            ->wherePivot('is_active', true)
            ->where('users.is_active', true)
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.color'])
            ->keyBy('id');

        $shifts = Shift::query()
            ->with(['user:id,name,color', 'shiftType'])
            ->forStore($store)
            ->between($firstDay, $lastDay)
            ->orderBy('starts_at')
            ->get();

        // Employés ayant un shift sans être membres actifs : visibles quand même, après les membres.
        $others = $shifts->pluck('user')->filter()->unique('id')->reject(fn (User $user): bool => $members->has($user->id))->sortBy('name');

        $days = [];
        for ($i = 0; $i < $numDays; $i++) {
            $date = $firstDay->addDays($i);
            $dayShifts = $shifts->filter(fn (Shift $shift): bool => $shift->shift_date->toDateString() === $date->toDateString());

            $rows = [];
            foreach ($members as $member) {
                $rows[] = $this->row($member, false, $dayShifts->where('user_id', $member->id), $withCost);
            }
            foreach ($others as $other) {
                $rows[] = $this->row($other, true, $dayShifts->where('user_id', $other->id), $withCost);
            }
            $rows[] = $this->row(null, false, $dayShifts->whereNull('user_id'), $withCost);

            $days[] = $this->day($store, $date, $rows, $withCost);
        }

        return $days;
    }

    /**
     * @param  Collection<int, Shift>  $shifts
     * @return Row
     */
    private function row(?User $user, bool $inactive, Collection $shifts, bool $withCost): array
    {
        $bars = [];
        $net = 0;
        $cost = $withCost ? 0.0 : null;

        foreach ($shifts->sortBy('starts_at') as $shift) {
            $layout = TimelineLayout::barOfShift($shift);
            if ($layout === null) {
                continue;
            }

            $shiftCost = $withCost ? $this->calculator->forShift($shift)->total : null;
            $net += $shift->netMinutes;
            $cost = $shiftCost === null ? null : (float) $cost + $shiftCost;

            $bars[] = [
                'id' => $shift->id,
                'user_id' => $shift->user_id,
                'left' => $layout['left'],
                'width' => $layout['width'],
                'start' => $shift->localStartsAt->format('H:i'),
                'end' => $shift->localEndsAt->format('H:i'),
                'startMinute' => $layout['start'],
                'duration' => $layout['end'] - $layout['start'],
                'net' => $shift->netMinutes,
                'color' => $shift->shiftType->color ?? $user->color ?? '#6366f1',
                'type' => $shift->shiftType?->name,
                'cutEnd' => $layout['cutEnd'],
                'lane' => 0,
                'cost' => $shiftCost,
                'notes' => $shift->notes,
            ];
        }

        // Voies : deux barres qui se chevauchent ne se superposent pas.
        $laneEnds = [];
        foreach ($bars as $index => $bar) {
            $lane = 0;
            while (isset($laneEnds[$lane]) && $laneEnds[$lane] > $bar['startMinute']) {
                $lane++;
            }
            $laneEnds[$lane] = $bar['startMinute'] + $bar['duration'];
            $bars[$index]['lane'] = $lane;
        }

        return [
            'user' => $user,
            'inactive' => $inactive,
            'bars' => $bars,
            'lanes' => max(1, count($laneEnds)),
            'net' => $net,
            'cost' => $cost,
        ];
    }

    /**
     * @param  list<Row>  $rows
     * @return Day
     */
    private function day(Store $store, CarbonImmutable $date, array $rows, bool $withCost): array
    {
        $staffIds = [];
        $shiftCount = 0;
        $net = 0;
        $cost = $withCost ? 0.0 : null;
        $spans = [];

        foreach ($rows as $row) {
            foreach ($row['bars'] as $bar) {
                $shiftCount++;
                if ($row['user'] !== null) {
                    $staffIds[$row['user']->id] = true;
                    $spans[] = [$row['user']->id, $bar['startMinute'], $bar['startMinute'] + $bar['duration']];
                }
            }
            $net += $row['net'];
            $cost = $cost === null ? null : $cost + (float) $row['cost'];
        }

        $staff = count($staffIds);
        $minStaff = $store->min_staff_per_day;

        $hourly = [];
        $lowHours = [];
        $understaffedHours = [];
        for ($slot = 0; $slot < 24; $slot++) {
            $from = $slot * 60;
            $present = [];
            foreach ($spans as [$userId, $start, $end]) {
                if ($start < $from + 60 && $end > $from) {
                    $present[$userId] = true;
                }
            }

            $count = count($present);
            $low = $this->isLowStaffHour($store, $slot);
            $hourly[] = $count;
            $lowHours[] = $low;
            // Comme l'ancienne version : une heure sans personne n'est pas un sous-effectif (magasin fermé).
            $understaffedHours[] = $minStaff > 0 && $count > 0 && $count < $minStaff && ! $low;
        }

        return [
            'date' => $date,
            'rows' => $rows,
            'staff' => $staff,
            'shifts' => $shiftCount,
            'understaffed' => $minStaff > 0 && $staff < $minStaff,
            'hourly' => $hourly,
            'lowHours' => $lowHours,
            'understaffedHours' => $understaffedHours,
            'net' => $net,
            'cost' => $cost,
        ];
    }

    /**
     * Heure d'horloge dans la plage à effectif réduit du magasin (la plage peut passer minuit).
     */
    private function isLowStaffHour(Store $store, int $hour): bool
    {
        $start = $store->low_staff_hour_start;
        $end = $store->low_staff_hour_end;

        if ($start === null || $end === null || $start === $end) {
            return false;
        }

        return $start < $end ? ($hour >= $start && $hour < $end) : ($hour >= $start || $hour < $end);
    }
}
