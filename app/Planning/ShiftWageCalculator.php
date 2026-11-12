<?php

namespace App\Planning;

use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Coût d'un shift par tranches horaires : un shift peut chevaucher plusieurs types (plages horaires des types du
 * magasin, minuit compris), chaque tranche est payée à son propre taux.
 *
 * Priorité des taux : `hourly_rate_override` du shift (taux plat sur les minutes nettes) > taux personnel de l'employé
 * pour le type > taux du type. Minutes nettes : `net_minutes_override`, sinon durée - pause.
 */
final class ShiftWageCalculator
{
    /**
     * Décomposition d'une plage horaire "H:i" sur les types donnés.
     *
     * @param  iterable<ShiftType>  $types  Dans l'ordre de priorité : le premier gagne à égalité de minutes.
     * @param  int|null  $netMinutes  Minutes payées (pause déduite) ; les tranches sont réduites proportionnellement.
     * @param  array<int, float>  $personalRates  Taux personnels par identifiant de type.
     */
    public function calculate(string $startTime, string $endTime, iterable $types, ?int $netMinutes = null, array $personalRates = []): WageBreakdown
    {
        $shiftStart = $this->toMinutes($startTime);
        $shiftEnd = $this->toMinutes($endTime);

        // Shift qui traverse minuit.
        if ($shiftEnd <= $shiftStart) {
            $shiftEnd += 1440;
        }

        /** @var list<array{id: int, name: string, minutes: int, rate: float}> $rows */
        $rows = [];
        $brutTotal = 0;
        $dominantId = null;
        $dominantMinutes = 0;

        foreach ($types as $type) {
            $typeStart = $this->toMinutes($type->starts_at);
            $typeEnd = $this->toMinutes($type->ends_at);

            // Tranche qui traverse minuit (ex. nuit 22:00 -> 06:00).
            if ($typeEnd <= $typeStart) {
                $typeEnd += 1440;
            }

            $minutes = $this->overlap($shiftStart, $shiftEnd, $typeStart, $typeEnd);
            if ($minutes === 0) {
                // Le type décalé au lendemain, pour les shifts tardifs.
                $minutes = $this->overlap($shiftStart, $shiftEnd, $typeStart + 1440, $typeEnd + 1440);
            }

            if ($minutes <= 0) {
                continue;
            }

            $brutTotal += $minutes;
            $rows[] = [
                'id' => $type->id,
                'name' => $type->name,
                'minutes' => $minutes,
                'rate' => $personalRates[$type->id] ?? (float) $type->hourly_rate,
            ];

            if ($minutes > $dominantMinutes) {
                $dominantMinutes = $minutes;
                $dominantId = $type->id;
            }
        }

        // Réduction proportionnelle quand une pause a été déduite.
        if ($netMinutes !== null && $brutTotal > 0 && $netMinutes < $brutTotal) {
            $factor = $netMinutes / $brutTotal;
            foreach ($rows as &$row) {
                $row['minutes'] = (int) round($row['minutes'] * $factor);
            }
            unset($row);
        }

        $slices = [];
        $total = 0.0;
        $dominantRate = 0.0;
        foreach ($rows as $row) {
            $amount = $row['rate'] > 0 ? round($row['rate'] * $row['minutes'] / 60, 2) : 0.0;
            $total += $amount;
            $slices[] = new WageSlice($row['id'], $row['name'], $row['minutes'], $row['rate'], $amount);

            if ($row['id'] === $dominantId) {
                $dominantRate = $row['rate'];
            }
        }

        usort($slices, fn (WageSlice $a, WageSlice $b): int => $a->shiftTypeId <=> $b->shiftTypeId);

        return new WageBreakdown($slices, $netMinutes ?? ($shiftEnd - $shiftStart), round($total, 2), $dominantRate, $dominantId);
    }

    /**
     * Coût d'un shift enregistré. `$types` : types actifs du magasin ; `$personalRates` : taux de l'employé du shift.
     *
     * @param  Collection<int, ShiftType>  $types
     * @param  array<int, float>  $personalRates
     */
    public function costOf(Shift $shift, Collection $types, array $personalRates = []): WageBreakdown
    {
        return $this->resolve(
            $shift->localStartsAt->format('H:i'),
            $shift->localEndsAt->format('H:i'),
            $shift->netMinutes,
            $shift->hourly_rate_override === null ? null : (float) $shift->hourly_rate_override,
            $shift->shift_type_id,
            $types,
            $personalRates,
        );
    }

    /**
     * Coût d'un shift enregistré, avec les types actifs de son magasin et les taux personnels de son employé.
     */
    public function forShift(Shift $shift): WageBreakdown
    {
        return $this->costOf($shift, $this->activeTypes($shift->store_id), $this->personalRates($shift->user));
    }

    /**
     * Aperçu pour un shift pas encore enregistré (heures locales "H:i").
     */
    public function estimate(
        Store $store,
        ?User $user,
        string $startTime,
        string $endTime,
        int $pauseMinutes = 0,
        ?float $rateOverride = null,
        ?int $netMinutesOverride = null,
    ): WageBreakdown {
        $start = $this->toMinutes($startTime);
        $end = $this->toMinutes($endTime);
        $gross = $end <= $start ? $end + 1440 - $start : $end - $start;

        return $this->resolve(
            $startTime,
            $endTime,
            $netMinutesOverride !== null ? max(0, $netMinutesOverride) : max(0, $gross - $pauseMinutes),
            $rateOverride,
            null,
            $this->activeTypes($store->id),
            $this->personalRates($user),
        );
    }

    /**
     * Type dominant d'une plage horaire parmi les types actifs du magasin, null s'il n'y en a pas.
     */
    public function dominantTypeId(Store $store, string $startTime, string $endTime): ?int
    {
        return $this->calculate($startTime, $endTime, $this->activeTypes($store->id))->dominantTypeId;
    }

    /**
     * @param  Collection<int, ShiftType>  $types
     * @param  array<int, float>  $personalRates
     */
    private function resolve(string $startTime, string $endTime, int $netMinutes, ?float $rateOverride, ?int $shiftTypeId, Collection $types, array $personalRates): WageBreakdown
    {
        if ($rateOverride === null) {
            return $this->calculate($startTime, $endTime, $types, $netMinutes, $personalRates);
        }

        // Le taux plat prime sur le découpage : une seule tranche.
        $amount = $rateOverride > 0 ? round($rateOverride * $netMinutes / 60, 2) : 0.0;
        $name = $shiftTypeId === null ? '' : (string) $types->firstWhere('id', $shiftTypeId)?->name;

        return new WageBreakdown(
            [new WageSlice($shiftTypeId, $name, $netMinutes, $rateOverride, $amount)],
            $netMinutes,
            $amount,
            $rateOverride,
            $shiftTypeId,
        );
    }

    /**
     * @return Collection<int, ShiftType>
     */
    public function activeTypes(int $storeId): Collection
    {
        return ShiftType::query()
            ->where('is_active', true)
            ->whereHas('stores', fn ($query) => $query->where('stores.id', $storeId))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<int, float>
     */
    public function personalRates(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $rates = [];
        foreach ($user->personalRates()->get() as $type) {
            $rates[$type->id] = (float) $type->getRelationValue('pivot')->hourly_rate;
        }

        return $rates;
    }

    private function toMinutes(string $time): int
    {
        $parts = explode(':', $time);

        return (int) $parts[0] * 60 + (int) ($parts[1] ?? 0);
    }

    private function overlap(int $aStart, int $aEnd, int $bStart, int $bEnd): int
    {
        return max(0, min($aEnd, $bEnd) - max($aStart, $bStart));
    }
}
