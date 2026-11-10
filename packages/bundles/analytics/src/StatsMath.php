<?php

namespace Kintai\Bundles\Analytics;

/**
 * Petits calculs partagés par les statistiques.
 */
final class StatsMath
{
    /**
     * Coefficient de Gini d'une répartition d'heures (0 = égalitaire) ; 0 sous deux valeurs ou sans heures.
     *
     * @param  list<float|int>  $values
     */
    public static function gini(array $values): float
    {
        $n = count($values);
        $total = array_sum($values);
        if ($n < 2 || $total <= 0) {
            return 0.0;
        }

        sort($values);
        $numerator = 0.0;
        foreach ($values as $i => $value) {
            $numerator += (2 * ($i + 1) - $n - 1) * $value;
        }

        return abs($numerator / ($n * $total));
    }

    /**
     * Séries de jours consécutifs d'une liste de jours (AAAA-MM-JJ) : longueurs des séries de deux jours ou plus, dans
     * l'ordre, et plus longue série (1 pour un jour isolé, 0 sans jour).
     *
     * @param  list<string>  $dates
     * @return array{max: int, runs: list<int>}
     */
    public static function consecutiveRuns(array $dates): array
    {
        $dates = array_values(array_unique($dates));
        sort($dates);

        $max = $dates === [] ? 0 : 1;
        $runs = [];
        $current = 1;
        for ($i = 1; $i < count($dates); $i++) {
            if (self::dayNumber($dates[$i]) - self::dayNumber($dates[$i - 1]) === 1) {
                $current++;
                $max = max($max, $current);

                continue;
            }
            if ($current > 1) {
                $runs[] = $current;
            }
            $current = 1;
        }
        if ($current > 1) {
            $runs[] = $current;
        }

        return ['max' => $max, 'runs' => $runs];
    }

    /**
     * Jours d'un congé, bornes incluses (au moins 1).
     */
    public static function inclusiveDays(string $start, string $end): int
    {
        return max(1, self::dayNumber($end) - self::dayNumber($start) + 1);
    }

    /**
     * Numéro de jour depuis l'époque, sans effet des changements d'heure.
     */
    public static function dayNumber(string $date): int
    {
        return intdiv((int) strtotime(substr($date, 0, 10).' 00:00:00 UTC'), 86400);
    }

    /**
     * Jour ISO (1 lundi … 7 dimanche) d'un jour AAAA-MM-JJ.
     */
    public static function isoDay(string $date): int
    {
        return (int) gmdate('N', (int) strtotime(substr($date, 0, 10).' 00:00:00 UTC'));
    }
}
