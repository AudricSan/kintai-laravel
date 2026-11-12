<?php

namespace Kintai\Bundles\Timeclock;

/**
 * Règles d'heures supplémentaires, entièrement paramétrables (la réglementation varie selon les pays) : seuil journalier
 * et seuil hebdomadaire en minutes (0 = pas de seuil), premier jour de la semaine (0 dimanche … 6 samedi).
 */
final class OvertimeRules
{
    public function __construct(
        public readonly bool $enabled,
        public readonly int $dailyMinutes,
        public readonly int $weeklyMinutes,
        public readonly int $weekStartsOn = 1,
    ) {}

    /**
     * Le suivi compte-t-il quelque chose : fonctionnalité activée et au moins un seuil.
     */
    public function active(): bool
    {
        return $this->enabled && ($this->dailyMinutes > 0 || $this->weeklyMinutes > 0);
    }
}
