<?php

namespace Kintai\Bundles\Analytics;

use App\Models\Shift;
use App\Planning\WageBreakdown;

/**
 * Un shift réduit à ce que les statistiques lisent, avec son coût déjà calculé (une seule fois par shift). Les heures sont
 * celles du fuseau de l'instance.
 */
final readonly class ShiftFact
{
    /**
     * @param  string  $date  Jour local de rattachement (AAAA-MM-JJ).
     * @param  string  $startTime  « HH:MM:SS » local.
     * @param  string  $endTime  « HH:MM:SS » local.
     * @param  int  $duration  Minutes brutes.
     * @param  int|null  $updatedAt  Horodatage de la dernière modification ; null tant que le shift n'a pas été modifié.
     */
    public function __construct(
        public int $userId,
        public string $date,
        public string $startTime,
        public string $endTime,
        public bool $crossesMidnight,
        public int $duration,
        public int $pause,
        public ?int $typeId,
        public int $createdBy,
        public int $startsAt,
        public int $endsAt,
        public int $createdAt,
        public ?int $updatedAt,
        public WageBreakdown $wage,
    ) {}

    public static function from(Shift $shift, WageBreakdown $wage): self
    {
        $createdAt = $shift->created_at?->getTimestamp() ?? $shift->starts_at->getTimestamp();
        $updatedAt = $shift->updated_at?->getTimestamp();

        return new self(
            (int) $shift->user_id,
            $shift->shift_date->toDateString(),
            $shift->localStartsAt->format('H:i:s'),
            $shift->localEndsAt->format('H:i:s'),
            $shift->crossesMidnight,
            $shift->durationMinutes,
            $shift->pause_minutes,
            $shift->shift_type_id,
            (int) $shift->created_by,
            $shift->starts_at->getTimestamp(),
            $shift->ends_at->getTimestamp(),
            $createdAt,
            $updatedAt !== null && $updatedAt > $createdAt ? $updatedAt : null,
            $wage,
        );
    }

    /**
     * Minutes de travail hors pause (sans la surcharge manuelle du shift).
     */
    public function plainNetMinutes(): int
    {
        return max(0, $this->duration - $this->pause);
    }
}
