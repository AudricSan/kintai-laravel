<?php

namespace Kintai\Bundles\DailyReport;

use App\Models\Shift;
use App\Models\StoreMembership;
use Illuminate\Support\Carbon;

/**
 * Le planning affiché avec un rapport : le rapport du jour J montre les shifts attribués du jour J+1 (le lendemain), pour
 * transmettre l'équipe de demain. Seuls comptent les employés réellement membres du magasin : un shift mal attribué ne
 * fait jamais apparaître le personnel d'un autre magasin.
 */
class DayShifts
{
    /**
     * @return list<array{employee: string, type: string, start: string, end: string, gross_min: int, pause_min: int, net_min: int}>
     */
    public function forReportDate(int $storeId, Carbon|string $reportDate): array
    {
        $day = Carbon::parse($reportDate)->addDay()->toDateString();
        $members = StoreMembership::query()->where('store_id', $storeId)->pluck('user_id')->all();

        $rows = [];
        $shifts = Shift::query()
            ->with(['user:id,name', 'shiftType:id,name'])
            ->where('store_id', $storeId)
            ->whereDate('shift_date', $day)
            ->whereNotNull('user_id')
            ->whereIn('user_id', $members)
            ->get();

        foreach ($shifts as $shift) {
            $rows[] = [
                'employee' => $shift->user->name ?? '—',
                'type' => $shift->shiftType->name ?? '—',
                'start' => $shift->localStartsAt->format('H:i'),
                'end' => $shift->localEndsAt->format('H:i'),
                'gross_min' => $shift->durationMinutes,
                'pause_min' => $shift->pause_minutes,
                'net_min' => $shift->netMinutes,
            ];
        }

        usort($rows, fn (array $a, array $b): int => [$a['start'], $a['employee']] <=> [$b['start'], $b['employee']]);

        return $rows;
    }

    public static function hours(int $minutes): string
    {
        return intdiv($minutes, 60).'h'.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
    }
}
