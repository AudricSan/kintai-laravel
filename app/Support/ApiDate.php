<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Format des dates de l'API : ISO 8601 en UTC, ex. `2026-11-05T09:30:00Z`.
 */
final class ApiDate
{
    public static function iso(?CarbonInterface $date): ?string
    {
        return $date?->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
