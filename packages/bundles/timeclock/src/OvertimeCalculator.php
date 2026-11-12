<?php

namespace Kintai\Bundles\Timeclock;

use Carbon\CarbonInterface;

/**
 * Calcul pur des heures supplémentaires d'une semaine calendaire, sans base de données ni réglages.
 *
 * Les pointages sont découpés à minuit local (un pointage à cheval sur deux jours compte pour chacun) puis parcourus dans
 * l'ordre chronologique : ce qui dépasse le seuil journalier est supplémentaire ; parmi le reste, ce qui dépasse le seuil
 * hebdomadaire l'est aussi (une heure déjà comptée par jour ne l'est pas deux fois). Un pointage sans sortie compte
 * jusqu'à `$now`. Un seuil à 0 ou une fonctionnalité désactivée ne produit aucune heure supplémentaire.
 */
class OvertimeCalculator
{
    /**
     * Premier jour (minuit local) de la semaine qui contient `$at`.
     */
    public function weekStart(CarbonInterface $at, OvertimeRules $rules, string $timezone): CarbonInterface
    {
        $local = $at->copy()->timezone($timezone)->startOfDay();
        $back = ($local->dayOfWeek - $rules->weekStartsOn + 7) % 7;

        return $local->subDays($back);
    }

    /**
     * @param  array<int|string, array{0: CarbonInterface, 1: CarbonInterface|null}>  $intervals  Clé => [entrée, sortie ou null].
     * @param  CarbonInterface  $weekStart  Début de la semaine, minuit local (voir `weekStart()`).
     */
    public function week(array $intervals, CarbonInterface $weekStart, OvertimeRules $rules, CarbonInterface $now, string $timezone): OvertimeReport
    {
        $start = $weekStart->copy()->timezone($timezone)->startOfDay();
        $end = $start->copy()->addDays(7);
        $dailyLimit = $rules->enabled ? $rules->dailyMinutes * 60 : 0;
        $weeklyLimit = $rules->enabled ? $rules->weeklyMinutes * 60 : 0;

        uasort($intervals, fn (array $a, array $b): int => $a[0]->getTimestamp() <=> $b[0]->getTimestamp());

        $days = [];
        $dailyOvertime = [];
        $entryOvertime = [];
        $worked = $net = $dailyTotal = $weeklyTotal = 0;

        foreach ($intervals as $key => [$in, $out]) {
            $cursor = $in->copy()->timezone($timezone);
            $stop = ($out ?? $now)->copy()->timezone($timezone);

            if ($cursor->lessThan($start)) {
                $cursor = $start->copy();
            }

            if ($stop->greaterThan($end)) {
                $stop = $end->copy();
            }

            while ($cursor->lessThan($stop)) {
                $midnight = $cursor->copy()->startOfDay()->addDay();
                $pieceEnd = $midnight->lessThan($stop) ? $midnight : $stop;
                $seconds = $pieceEnd->getTimestamp() - $cursor->getTimestamp();
                $day = $cursor->toDateString();

                $before = $days[$day] ?? 0;
                $days[$day] = $before + $seconds;

                $daily = $dailyLimit > 0 ? max(0, min($seconds, $before + $seconds - $dailyLimit)) : 0;
                $normal = $seconds - $daily;
                $weekly = $weeklyLimit > 0 ? max(0, min($normal, $net + $normal - $weeklyLimit)) : 0;

                $net += $normal;
                $worked += $seconds;
                $dailyTotal += $daily;
                $weeklyTotal += $weekly;
                $dailyOvertime[$day] = ($dailyOvertime[$day] ?? 0) + $daily;
                $entryOvertime[$key] = ($entryOvertime[$key] ?? 0) + $daily + $weekly;

                $cursor = $pieceEnd->copy();
            }
        }

        return new OvertimeReport(
            $start->toDateString(),
            intdiv($worked, 60),
            intdiv($net, 60),
            intdiv($dailyTotal, 60),
            intdiv($weeklyTotal, 60),
            array_map(fn (int $s): int => intdiv($s, 60), $days),
            array_filter(array_map(fn (int $s): int => intdiv($s, 60), $dailyOvertime)),
            array_map(fn (int $s): int => intdiv($s, 60), $entryOvertime),
        );
    }
}
