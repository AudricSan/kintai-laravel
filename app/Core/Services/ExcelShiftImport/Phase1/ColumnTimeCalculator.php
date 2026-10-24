<?php

declare(strict_types=1);

namespace kintai\Core\Services\ExcelShiftImport\Phase1;

/**
 * ColumnTimeCalculator — grille temporelle configurable par store
 *
 * Toutes les constantes sont maintenant paramétrables :
 *   col_start      : index de la première colonne de données (défaut : 4 = col D)
 *   col_end        : index de la dernière colonne (défaut : 52 = col AZ)
 *   base_hour      : heure de départ de la grille (défaut : 6 → 06:00)
 *   minutes_per_col: minutes représentées par chaque colonne (défaut : 30)
 *
 * Exemple par défaut :
 *   col 4 (D) = 06:00, col 5 (E) = 06:30, col 6 (F) = 07:00 … col 52 (AZ) = 23:30
 */
final class ColumnTimeCalculator
{
    private int $colStart;
    private int $colEnd;
    private int $baseHour;
    private int $minutesPerCol;

    public function __construct(array $settings = [])
    {
        $this->colStart      = (int) ($settings['col_start']       ?? 4);
        $this->colEnd        = (int) ($settings['col_end']         ?? 52);
        $this->baseHour      = (int) ($settings['base_hour']       ?? 6);
        $this->minutesPerCol = (int) ($settings['minutes_per_col'] ?? 30);

        if ($this->colStart < 1)                       $this->colStart = 1;
        if ($this->colEnd   < $this->colStart)         $this->colEnd   = $this->colStart;
        if ($this->baseHour < 0 || $this->baseHour > 23) $this->baseHour = 6;
        if ($this->minutesPerCol < 1)                  $this->minutesPerCol = 30;
    }

    public function calculateTime(int $colIndex): array
    {
        if ($colIndex < $this->colStart || $colIndex > $this->colEnd) {
            throw new \InvalidArgumentException(
                "Colonne {$colIndex} hors plage [{$this->colStart}–{$this->colEnd}]"
            );
        }

        $minutesOffset = ($colIndex - $this->colStart) * $this->minutesPerCol;
        $totalMinutes  = $this->baseHour * 60 + $minutesOffset;
        $hour          = intdiv($totalMinutes, 60) % 24;
        $minute        = $totalMinutes % 60;

        return [
            'hour'        => $hour,
            'minute'      => $minute,
            'time_string' => sprintf('%02d:%02d', $hour, $minute),
        ];
    }

    public function calculateRange(int $startCol, int $endCol): array
    {
        if ($endCol < $startCol) {
            throw new \InvalidArgumentException("endCol ({$endCol}) < startCol ({$startCol})");
        }

        $startTime = $this->calculateTime($startCol);
        $endColIdx = min($endCol + 1, $this->colEnd);
        $endTime   = $this->calculateTime($endColIdx);
        $cellCount = $endCol - $startCol + 1;

        return [
            'start'            => $startTime['time_string'],
            'end'              => $endTime['time_string'],
            'duration_minutes' => $cellCount * $this->minutesPerCol,
            'cell_count'       => $cellCount,
        ];
    }

    public function getStartColumn(): int    { return $this->colStart; }
    public function getEndColumn(): int      { return $this->colEnd; }
    public function getMinutesPerCol(): int  { return $this->minutesPerCol; }
    public function getBaseHour(): int       { return $this->baseHour; }

    public function isValidColumn(int $colIndex): bool
    {
        return $colIndex >= $this->colStart && $colIndex <= $this->colEnd;
    }
}
