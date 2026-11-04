<?php

namespace App\Contracts\Planning;

/**
 * Un congé approuvé : jours locaux, bornes incluses.
 */
final readonly class TimeOffPeriod
{
    /**
     * @param  string  $uid  Identifiant stable du congé dans les agendas (ex. « timeoff-12 »).
     * @param  string  $startDate  AAAA-MM-JJ
     * @param  string  $endDate  AAAA-MM-JJ, inclus
     */
    public function __construct(
        public string $uid,
        public string $startDate,
        public string $endDate,
    ) {}
}
