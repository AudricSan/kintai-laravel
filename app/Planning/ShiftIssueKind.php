<?php

namespace App\Planning;

enum ShiftIssueKind: string
{
    case TimeOff = 'time_off';
    case Unavailable = 'unavailable';
    case Overlap = 'overlap';
    case TooShort = 'too_short';
    case TooLong = 'too_long';

    /**
     * Seul le congé approuvé bloque ; le reste est un avertissement que la confirmation (`force`) contourne.
     */
    public function isBlocking(): bool
    {
        return $this === self::TimeOff;
    }
}
