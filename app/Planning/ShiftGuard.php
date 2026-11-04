<?php

namespace App\Planning;

use App\Contracts\Planning\TimeOffChecker;
use App\Models\Shift;
use App\Models\Store;

/**
 * Garde-fous d'un shift avant enregistrement. Renvoie tous les problèmes à la fois : le congé approuvé bloque,
 * le reste (indisponibilité, chevauchement, durée hors bornes) est un avertissement contournable.
 * Un shift ouvert (sans employé) n'est soumis qu'au contrôle de durée.
 */
class ShiftGuard
{
    public function __construct(
        private readonly TimeOffChecker $timeOff,
        private readonly AvailabilityGuard $availability,
    ) {}

    /**
     * @param  Shift  $shift  Shift à enregistrer (neuf ou modifié, pas encore sauvegardé).
     * @param  bool  $checkSchedule  Congé, chevauchement et durée : à faire quand le créneau change.
     * @param  bool  $checkPlacement  Indisponibilité : à faire quand l'employé, le jour ou le magasin change.
     * @param  list<int>  $ignoreShiftIds  Shifts qui disparaîtront (remplacés) et ne comptent donc pas.
     * @return list<ShiftIssue>
     */
    public function check(Shift $shift, bool $checkSchedule = true, bool $checkPlacement = true, array $ignoreShiftIds = []): array
    {
        $issues = [];
        $user = $shift->user_id === null ? null : $shift->user;

        if ($user !== null && $checkSchedule && $this->timeOff->hasApprovedTimeOff($user, $shift->shift_date)) {
            $issues[] = new ShiftIssue(ShiftIssueKind::TimeOff, __(':name has approved time off on :date.', [
                'name' => $user->name,
                'date' => $shift->shift_date->toDateString(),
            ]));
        }

        if ($user !== null && $checkPlacement
            && ($weekday = $this->availability->unavailableWeekday($user->id, $shift->store_id, $shift->shift_date)) !== null) {
            $issues[] = new ShiftIssue(ShiftIssueKind::Unavailable, __(':name declared being unavailable on :weekday (:date).', [
                'name' => $user->name,
                'weekday' => __(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'][$weekday - 1]),
                'date' => $shift->shift_date->toDateString(),
            ]));
        }

        if ($user !== null && $checkSchedule) {
            $query = Shift::query()
                ->where('user_id', $user->id)
                ->whereNull('replaced_by_id')
                ->where('starts_at', '<', $shift->ends_at)
                ->where('ends_at', '>', $shift->starts_at);

            if ($shift->exists) {
                $ignoreShiftIds[] = $shift->id;
            }
            if ($ignoreShiftIds !== []) {
                $query->whereNotIn('id', $ignoreShiftIds);
            }

            foreach ($query->orderBy('starts_at')->get() as $other) {
                $issues[] = new ShiftIssue(ShiftIssueKind::Overlap, __('Overlaps another shift of :name: :from - :to.', [
                    'name' => $user->name,
                    'from' => $other->localStartsAt->format('Y-m-d H:i'),
                    'to' => $other->localEndsAt->format('H:i'),
                ]), $other->id);
            }
        }

        if ($checkSchedule) {
            $issues = [...$issues, ...$this->durationIssues($shift)];
        }

        return $issues;
    }

    /**
     * @return list<ShiftIssue>
     */
    private function durationIssues(Shift $shift): array
    {
        $store = $shift->store ?? Store::query()->find($shift->store_id);
        if ($store === null) {
            return [];
        }

        $duration = $shift->durationMinutes;

        if ($store->min_shift_minutes > 0 && $duration < $store->min_shift_minutes) {
            return [new ShiftIssue(ShiftIssueKind::TooShort, __('The shift lasts :duration min, below the store minimum of :limit min.', [
                'duration' => $duration,
                'limit' => $store->min_shift_minutes,
            ]))];
        }

        if ($store->max_shift_minutes > 0 && $duration > $store->max_shift_minutes) {
            return [new ShiftIssue(ShiftIssueKind::TooLong, __('The shift lasts :duration min, above the store maximum of :limit min.', [
                'duration' => $duration,
                'limit' => $store->max_shift_minutes,
            ]))];
        }

        return [];
    }
}
