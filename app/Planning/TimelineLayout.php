<?php

namespace App\Planning;

use App\Models\Shift;

/**
 * Géométrie de la frise : l'axe d'un jour va de 00:00 à 24:00 (1 440 minutes) du jour de rattachement du shift.
 * Un shift qui passe minuit est coupé à 24:00 (la barre est marquée `cutEnd`, avec une flèche à l'écran) :
 * sa suite n'est pas dessinée sur le jour suivant, qui ne contient que ses propres shifts.
 */
final class TimelineLayout
{
    public const DAY_MINUTES = 1440;

    /** Pas du glisser-déposer et de la création rapide. */
    public const SNAP_MINUTES = 15;

    /**
     * Barre d'une plage horaire locale "H:i" ; la fin avant ou égale au début passe minuit.
     *
     * @return array{left: float, width: float, start: int, end: int, cutEnd: bool}|null
     */
    public static function bar(string $startTime, string $endTime): ?array
    {
        $start = self::minutes($startTime);
        $end = self::minutes($endTime);
        $duration = $end <= $start ? $end + self::DAY_MINUTES - $start : $end - $start;

        return self::clip($start, $start + $duration);
    }

    /**
     * Barre d'un shift ; la durée réelle (écart entre les instants) fait foi pour la fin.
     *
     * @return array{left: float, width: float, start: int, end: int, cutEnd: bool}|null
     */
    public static function barOfShift(Shift $shift): ?array
    {
        $dayOffset = (int) $shift->shift_date->copy()->startOfDay()->diffInDays($shift->localStartsAt->copy()->startOfDay(), false);
        $start = self::minutes($shift->localStartsAt->format('H:i')) + $dayOffset * self::DAY_MINUTES;

        return self::clip($start, $start + $shift->durationMinutes);
    }

    /**
     * "H:i" de minutes depuis minuit du jour de rattachement (au-delà de 24:00 : le lendemain).
     */
    public static function format(int $minutes): string
    {
        $minutes = (($minutes % self::DAY_MINUTES) + self::DAY_MINUTES) % self::DAY_MINUTES;

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * Arrondit au pas de 15 minutes, dans [0, 1440 - pas] pour qu'un début reste dans le jour.
     */
    public static function snap(int $minutes): int
    {
        $snapped = (int) (round($minutes / self::SNAP_MINUTES) * self::SNAP_MINUTES);

        return max(0, min(self::DAY_MINUTES - self::SNAP_MINUTES, $snapped));
    }

    public static function minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time) + [0, 0]);

        return $hours * 60 + $minutes;
    }

    /**
     * @return array{left: float, width: float, start: int, end: int, cutEnd: bool}|null
     */
    private static function clip(int $start, int $end): ?array
    {
        $visibleStart = max($start, 0);
        $visibleEnd = min($end, self::DAY_MINUTES);

        if ($visibleEnd <= $visibleStart) {
            return null;
        }

        return [
            'left' => round($visibleStart / self::DAY_MINUTES * 100, 4),
            'width' => round(($visibleEnd - $visibleStart) / self::DAY_MINUTES * 100, 4),
            'start' => $visibleStart,
            'end' => $end,
            'cutEnd' => $end > self::DAY_MINUTES,
        ];
    }
}
