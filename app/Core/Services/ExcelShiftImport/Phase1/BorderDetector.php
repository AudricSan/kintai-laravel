<?php

declare(strict_types=1);

namespace kintai\Core\Services\ExcelShiftImport\Phase1;

use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Border;

/**
 * BorderDetector — détection des bordures de cellule
 *
 * Les bordures haut + bas sont le critère clé pour valider
 * les segments à fond blanc (shifts responsable/manager).
 */
final class BorderDetector
{
    public function hasTopBorder(Worksheet $sheet, int $col, int $row): bool
    {
        $coord   = Coordinate::stringFromColumnIndex($col) . $row;
        $borders = $sheet->getCell($coord)->getStyle()->getBorders();
        return $this->hasBorder($borders->getTop());
    }

    public function hasBottomBorder(Worksheet $sheet, int $col, int $row): bool
    {
        $coord   = Coordinate::stringFromColumnIndex($col) . $row;
        $borders = $sheet->getCell($coord)->getStyle()->getBorders();
        return $this->hasBorder($borders->getBottom());
    }

    public function hasAnyBorder(Worksheet $sheet, int $col, int $row): bool
    {
        $coord   = Coordinate::stringFromColumnIndex($col) . $row;
        $borders = $sheet->getCell($coord)->getStyle()->getBorders();
        return $this->hasBorder($borders->getTop())
            || $this->hasBorder($borders->getBottom())
            || $this->hasBorder($borders->getLeft())
            || $this->hasBorder($borders->getRight());
    }

    private function hasBorder(Border $border): bool
    {
        $style = (string) $border->getBorderStyle();
        return $style !== '' && strtolower($style) !== 'none' && $style !== Border::BORDER_NONE;
    }
}
