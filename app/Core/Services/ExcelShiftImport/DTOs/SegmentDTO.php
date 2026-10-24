<?php

declare(strict_types=1);

namespace kintai\Core\Services\ExcelShiftImport\DTOs;

/**
 * Segment DTO — Phase 1
 *
 * Représente un segment horizontal continu détecté dans l'Excel.
 * Sortie brute de la détection visuelle, sans logique métier.
 */
final class SegmentDTO
{
    public function __construct(
        public readonly int     $rowNumber,
        public readonly string  $date,
        public readonly int     $startCol,
        public readonly int     $endCol,
        public readonly string  $startTime,
        public readonly string  $endTime,
        public readonly int     $durationMinutes,
        public readonly int     $cellCount,
        public readonly ?string $color,
        public readonly bool    $hasTopBorder,
        public readonly bool    $hasBottomBorder,
        public readonly ?string $employeeName,
        public readonly array   $metadata = []
    ) {}

    public function isWhite(): bool
    {
        if ($this->color === null) return true;
        $c = strtoupper(trim($this->color));
        return in_array($c, ['FFFFFFFF', 'FFFFFF', 'FF000000'], true);
    }

    public function getDurationHours(): float
    {
        return $this->durationMinutes / 60.0;
    }

    public function hasBothBorders(): bool
    {
        return $this->hasTopBorder && $this->hasBottomBorder;
    }

    public function toArray(): array
    {
        return [
            'row_number'       => $this->rowNumber,
            'date'             => $this->date,
            'start_col'        => $this->startCol,
            'end_col'          => $this->endCol,
            'start_time'       => $this->startTime,
            'end_time'         => $this->endTime,
            'duration_minutes' => $this->durationMinutes,
            'duration_hours'   => round($this->durationMinutes / 60, 2),
            'cell_count'       => $this->cellCount,
            'color'            => $this->color,
            'is_white'         => $this->isWhite(),
            'has_top_border'   => $this->hasTopBorder,
            'has_bottom_border'=> $this->hasBottomBorder,
            'has_both_borders' => $this->hasBothBorders(),
            'employee_name'    => $this->employeeName,
            'metadata'         => $this->metadata,
        ];
    }
}
