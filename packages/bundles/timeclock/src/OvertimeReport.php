<?php

namespace Kintai\Bundles\Timeclock;

/**
 * Résultat du calcul d'une semaine, en minutes (arrondies à la minute inférieure).
 */
final class OvertimeReport
{
    /**
     * @param  string  $weekStart  Premier jour de la semaine, `AAAA-MM-JJ` (fuseau de l'instance).
     * @param  int  $workedMinutes  Total travaillé dans la semaine.
     * @param  int  $netMinutes  Total travaillé hors heures supplémentaires journalières (base du seuil hebdomadaire).
     * @param  int  $dailyOvertimeMinutes  Heures au-delà du seuil journalier.
     * @param  int  $weeklyOvertimeMinutes  Heures au-delà du seuil hebdomadaire, hors celles déjà comptées par jour.
     * @param  array<string, int>  $days  Jour `AAAA-MM-JJ` => minutes travaillées.
     * @param  array<string, int>  $dailyOvertime  Jour => heures supplémentaires journalières.
     * @param  array<int|string, int>  $entryOvertime  Clé du pointage => heures supplémentaires qu'il porte.
     */
    public function __construct(
        public readonly string $weekStart,
        public readonly int $workedMinutes,
        public readonly int $netMinutes,
        public readonly int $dailyOvertimeMinutes,
        public readonly int $weeklyOvertimeMinutes,
        public readonly array $days,
        public readonly array $dailyOvertime,
        public readonly array $entryOvertime,
    ) {}

    public function overtimeMinutes(): int
    {
        return $this->dailyOvertimeMinutes + $this->weeklyOvertimeMinutes;
    }

    public function forEntry(int|string $key): int
    {
        return $this->entryOvertime[$key] ?? 0;
    }
}
