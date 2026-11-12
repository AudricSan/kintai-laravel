<?php

namespace App\Planning;

use App\Models\Shift;
use Illuminate\Support\Collection;

/**
 * Détection des conflits entre shifts actifs (ni supprimés ni remplacés) : chevauchements d'un même employé
 * (passage de minuit compris, les horodatages sont absolus) et durées hors bornes du magasin.
 */
class ShiftConflictFinder
{
    public function overlaps(Shift $a, Shift $b): bool
    {
        return $a->user_id !== null
            && $a->user_id === $b->user_id
            && $a->starts_at->lt($b->ends_at)
            && $b->starts_at->lt($a->ends_at);
    }

    public function overlapMinutes(Shift $a, Shift $b): int
    {
        $start = max($a->starts_at->getTimestamp(), $b->starts_at->getTimestamp());
        $end = min($a->ends_at->getTimestamp(), $b->ends_at->getTimestamp());

        return max(0, intdiv($end - $start, 60));
    }

    /**
     * @param  iterable<Shift>  $shifts
     * @return list<ShiftConflict>
     */
    public function overlappingPairs(iterable $shifts): array
    {
        $conflicts = [];

        foreach (Collection::make($shifts)->whereNotNull('user_id')->groupBy('user_id') as $userShifts) {
            $sorted = $userShifts->sortBy(fn (Shift $shift): int => $shift->starts_at->getTimestamp())->values();

            foreach ($sorted as $i => $a) {
                for ($j = $i + 1; $j < $sorted->count(); $j++) {
                    $b = $sorted[$j];
                    if ($b->starts_at->gte($a->ends_at)) {
                        break;
                    }
                    if ($this->overlaps($a, $b)) {
                        $conflicts[] = new ShiftConflict($a, $b, $this->overlapMinutes($a, $b));
                    }
                }
            }
        }

        return $conflicts;
    }

    /**
     * @param  iterable<Shift>  $shifts  Avec leur magasin chargé.
     * @return Collection<int, Shift>
     */
    public function tooShort(iterable $shifts): Collection
    {
        return Collection::make($shifts)->filter(
            fn (Shift $shift): bool => $shift->store->min_shift_minutes > 0 && $shift->durationMinutes < $shift->store->min_shift_minutes
        )->values();
    }

    /**
     * @param  iterable<Shift>  $shifts  Avec leur magasin chargé.
     * @return Collection<int, Shift>
     */
    public function tooLong(iterable $shifts): Collection
    {
        return Collection::make($shifts)->filter(
            fn (Shift $shift): bool => $shift->store->max_shift_minutes > 0 && $shift->durationMinutes > $shift->store->max_shift_minutes
        )->values();
    }
}
