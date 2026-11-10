<?php

namespace Kintai\Bundles\PlanningImport\Detection;

use InvalidArgumentException;

/**
 * Phase 1 : grille horaire d'un magasin. Avec les défauts, la colonne 4 (D) vaut 06:00, la 5 (E) 06:30, la 52 (AZ) 23:30.
 */
final class ColumnTimeCalculator
{
    public function __construct(private readonly GridSettings $grid) {}

    /**
     * @return array{start: string, end: string, duration_minutes: int, cell_count: int}
     */
    public function range(int $startCol, int $endCol): array
    {
        if ($endCol < $startCol) {
            throw new InvalidArgumentException("endCol ({$endCol}) < startCol ({$startCol})");
        }

        $cells = $endCol - $startCol + 1;

        return [
            'start' => $this->time($startCol),
            // La dernière colonne de la grille se termine sur sa propre heure, comme à l'ancienne version.
            'end' => $this->time(min($endCol + 1, $this->grid->colEnd)),
            'duration_minutes' => $cells * $this->grid->minutesPerCol,
            'cell_count' => $cells,
        ];
    }

    private function time(int $col): string
    {
        if ($col < $this->grid->colStart || $col > $this->grid->colEnd) {
            throw new InvalidArgumentException("Column {$col} out of range [{$this->grid->colStart}-{$this->grid->colEnd}]");
        }

        $total = $this->grid->baseHour * 60 + ($col - $this->grid->colStart) * $this->grid->minutesPerCol;

        return sprintf('%02d:%02d', intdiv($total, 60) % 24, $total % 60);
    }
}
