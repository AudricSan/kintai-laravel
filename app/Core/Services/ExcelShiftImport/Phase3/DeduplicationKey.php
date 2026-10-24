<?php

declare(strict_types=1);

namespace kintai\Core\Services\ExcelShiftImport\Phase3;

/**
 * DeduplicationKey — génération de clé unique de déduplication
 *
 * Format : "nom|date|début|fin"
 * Exemple : "小田|2025-01-03|06:00|14:00"
 */
final class DeduplicationKey
{
    private const SEP = '|';

    public function generate(?string $employeeName, string $date, string $startTime, string $endTime): string
    {
        $name = ($employeeName !== null && trim($employeeName) !== '')
            ? trim($employeeName)
            : 'UNKNOWN';

        return implode(self::SEP, [
            $name,
            trim($date),
            trim($startTime),
            trim($endTime),
        ]);
    }
}
