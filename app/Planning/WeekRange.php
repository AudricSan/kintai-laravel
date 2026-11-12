<?php

namespace App\Planning;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Semaines et grille mensuelle selon le jour de début de semaine du magasin (1 = lundi ... 7 = dimanche, ISO).
 */
final class WeekRange
{
    public static function normalize(int $weekStartDay): int
    {
        return max(1, min(7, $weekStartDay));
    }

    /**
     * Premier jour de la semaine contenant `$day`.
     */
    public static function firstDay(CarbonInterface $day, int $weekStartDay): CarbonImmutable
    {
        $day = CarbonImmutable::parse($day->toDateString());
        $offset = ($day->dayOfWeekIso - self::normalize($weekStartDay) + 7) % 7;

        return $day->subDays($offset);
    }

    /**
     * Les semaines (listes de 7 jours) qui couvrent le mois de `$month`, la première commençant au jour de début de semaine.
     *
     * @return list<list<CarbonImmutable>>
     */
    public static function monthGrid(CarbonInterface $month, int $weekStartDay): array
    {
        $first = CarbonImmutable::parse($month->toDateString())->startOfMonth();
        $last = $first->endOfMonth()->startOfDay();

        $weeks = [];
        for ($cursor = self::firstDay($first, $weekStartDay); $cursor->lessThanOrEqualTo($last); $cursor = $cursor->addDays(7)) {
            $weeks[] = array_map(fn (int $i): CarbonImmutable => $cursor->addDays($i), range(0, 6));
        }

        return $weeks;
    }
}
