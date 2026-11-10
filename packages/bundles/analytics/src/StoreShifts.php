<?php

namespace Kintai\Bundles\Analytics;

use App\Models\Shift;
use App\Models\ShiftType;
use App\Planning\ShiftWageCalculator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lecture des shifts d'un magasin pour les statistiques : shifts supprimés et shifts ouverts (sans employé) exclus, coût
 * calculé par le `ShiftWageCalculator` du Core avec les types actifs du magasin et les taux personnels des employés.
 */
class StoreShifts
{
    public function __construct(private readonly ShiftWageCalculator $calculator) {}

    /**
     * @return list<ShiftFact> Triés par jour, début puis identifiant.
     */
    public function between(int $storeId, string $from, string $to, ?int $userId = null): array
    {
        $shifts = Shift::query()
            ->where('store_id', $storeId)
            ->whereNotNull('user_id')
            ->when($userId !== null, fn ($query) => $query->where('user_id', $userId))
            ->whereBetween('shift_date', [$from, $to])
            ->orderBy('shift_date')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        return $this->facts($shifts, $this->calculator->activeTypes($storeId));
    }

    /**
     * Types actifs du magasin par identifiant.
     *
     * @return array<int, ShiftType>
     */
    public function typesOf(int $storeId): array
    {
        return $this->calculator->activeTypes($storeId)->keyBy('id')->all();
    }

    /**
     * @param  Collection<int, Shift>  $shifts
     * @param  Collection<int, ShiftType>  $types
     * @return list<ShiftFact>
     */
    public function facts(Collection $shifts, \Illuminate\Support\Collection $types): array
    {
        $rates = $this->personalRates($shifts->pluck('user_id')->unique()->filter()->map(fn ($id): int => (int) $id)->all());

        return $shifts->map(fn (Shift $shift): ShiftFact => ShiftFact::from(
            $shift,
            $this->calculator->costOf($shift, $types, $rates[(int) $shift->user_id] ?? []),
        ))->values()->all();
    }

    /**
     * Taux horaires personnels : employé => (type => taux).
     *
     * @param  list<int>  $userIds
     * @return array<int, array<int, float>>
     */
    public function personalRates(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $rates = [];
        foreach (DB::table('shift_type_user')->whereIn('user_id', $userIds)->get(['user_id', 'shift_type_id', 'hourly_rate']) as $row) {
            $rates[(int) $row->user_id][(int) $row->shift_type_id] = (float) $row->hourly_rate;
        }

        return $rates;
    }

    /**
     * Appartenances d'un magasin (toutes, actives ou non), en lignes simples.
     *
     * @return list<array{user_id: int, staff_code: string|null, hire_date: string|null, contract_type: string|null, is_active: bool}>
     */
    public function memberships(int $storeId): array
    {
        return DB::table('store_user')->where('store_id', $storeId)->orderBy('id')->get()
            ->map(fn ($row): array => [
                'user_id' => (int) $row->user_id,
                'staff_code' => $row->staff_code,
                'hire_date' => $row->hire_date === null ? null : substr((string) $row->hire_date, 0, 10),
                'contract_type' => $row->contract_type,
                'is_active' => (bool) $row->is_active,
            ])
            ->all();
    }
}
