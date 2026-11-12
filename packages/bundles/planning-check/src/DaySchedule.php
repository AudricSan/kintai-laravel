<?php

namespace Kintai\Bundles\PlanningCheck;

use App\Models\Shift;
use Illuminate\Database\Eloquent\Collection;

/**
 * Les shifts d'un magasin pour un jour, prêts à être affichés (aperçu, historique) et imprimés (PDF) : par employé, avec
 * type, horaires et durée nette, et le total d'heures. Un shift supprimé n'y figure jamais ; un shift ouvert (sans employé)
 * n'y figure que si le magasin l'a demandé.
 */
class DaySchedule
{
    /**
     * Les shifts du jour (supprimés exclus).
     *
     * @return Collection<int, Shift>
     */
    public function shifts(int $storeId, string $date): Collection
    {
        return Shift::query()->with(['user:id,name', 'shiftType:id,name,color'])->where('store_id', $storeId)->whereDate('shift_date', $date)->get();
    }

    /**
     * @return array{rows: list<array{employee: string|null, type: string, color: string|null, start: string, end: string, net_min: int, open: bool}>, total_minutes: int, assigned: int, open: int}
     */
    public function forDay(int $storeId, string $date, bool $includeOpen): array
    {
        $rows = [];
        $assigned = 0;
        $open = 0;

        foreach ($this->shifts($storeId, $date) as $shift) {
            if ($shift->user_id === null) {
                $open++;
                if (! $includeOpen) {
                    continue;
                }
            } else {
                $assigned++;
            }

            $rows[] = [
                'employee' => $shift->user?->name,
                'type' => $shift->shiftType->name ?? '—',
                'color' => $shift->shiftType->color ?? null,
                'start' => $shift->localStartsAt->format('H:i'),
                'end' => $shift->localEndsAt->format('H:i'),
                'net_min' => $shift->netMinutes,
                'open' => $shift->user_id === null,
            ];
        }

        usort($rows, fn (array $a, array $b): int => [$a['start'], (string) $a['employee']] <=> [$b['start'], (string) $b['employee']]);

        return ['rows' => $rows, 'total_minutes' => array_sum(array_column($rows, 'net_min')), 'assigned' => $assigned, 'open' => $open];
    }

    public static function hours(int $minutes): string
    {
        return intdiv($minutes, 60).'h'.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
    }
}
