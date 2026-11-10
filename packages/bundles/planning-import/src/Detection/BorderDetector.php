<?php

namespace Kintai\Bundles\PlanningImport\Detection;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Phase 1 : bordures d'une cellule. Les bordures haut et bas valident les segments à fond blanc (shifts des responsables).
 */
final class BorderDetector
{
    public function hasTop(Worksheet $sheet, int $col, int $row): bool
    {
        return $this->has($sheet->getCell(Coordinate::stringFromColumnIndex($col).$row)->getStyle()->getBorders()->getTop());
    }

    public function hasBottom(Worksheet $sheet, int $col, int $row): bool
    {
        return $this->has($sheet->getCell(Coordinate::stringFromColumnIndex($col).$row)->getStyle()->getBorders()->getBottom());
    }

    private function has(Border $border): bool
    {
        $style = (string) $border->getBorderStyle();

        return $style !== '' && strtolower($style) !== 'none';
    }
}
