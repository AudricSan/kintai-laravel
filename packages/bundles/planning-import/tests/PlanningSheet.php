<?php

namespace Kintai\Bundles\PlanningImport\Tests;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Fichier de planning synthétique pour les tests : des blocs de 9 lignes (un jour par bloc, date en colonne A de la
 * 3e ligne, shifts sur les lignes 2 à 8), une colonne par demi-heure à partir de la colonne D (06:00). Aucun fichier
 * client n'est stocké dans le dépôt : les cellules colorées ou bordées représentent des segments de shift.
 */
class PlanningSheet
{
    public Spreadsheet $spreadsheet;

    public Worksheet $sheet;

    /** @var list<string> */
    private array $files = [];

    public function __construct(string $title = '2611')
    {
        $this->spreadsheet = new Spreadsheet;
        $this->sheet = $this->spreadsheet->getActiveSheet();
        $this->sheet->setTitle($title);
    }

    /**
     * Ligne de départ du bloc du jour n° `$day` (1 = premier bloc).
     */
    public static function block(int $day): int
    {
        return 1 + ($day - 1) * 9;
    }

    /**
     * Écrit la date du bloc (texte « Y-m-d » ou numéro de série Excel).
     */
    public function date(int $day, string|int|float $date, ?Worksheet $sheet = null): self
    {
        ($sheet ?? $this->sheet)->setCellValue('A'.(self::block($day) + 2), $date);

        return $this;
    }

    /**
     * Segment coloré (la couleur identifie l'employé) de `$from` à `$to` (colonnes incluses), nom dans la première cellule.
     */
    public function colored(int $day, int $row, int $from, int $to, string $rgb, ?string $name, ?Worksheet $sheet = null): self
    {
        $sheet ??= $this->sheet;
        $line = self::block($day) + $row;

        for ($col = $from; $col <= $to; $col++) {
            $sheet->getStyle(Coordinate::stringFromColumnIndex($col).$line)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($rgb);
        }

        if ($name !== null) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($from).$line, $name);
        }

        return $this;
    }

    /**
     * Segment à fond blanc : bordures haut et bas (shift de responsable), nom dans la première cellule.
     */
    public function bordered(int $day, int $row, int $from, int $to, ?string $name, bool $top = true, bool $bottom = true, ?Worksheet $sheet = null): self
    {
        $sheet ??= $this->sheet;
        $line = self::block($day) + $row;

        for ($col = $from; $col <= $to; $col++) {
            $borders = $sheet->getStyle(Coordinate::stringFromColumnIndex($col).$line)->getBorders();
            if ($top) {
                $borders->getTop()->setBorderStyle(Border::BORDER_THIN);
            }
            if ($bottom) {
                $borders->getBottom()->setBorderStyle(Border::BORDER_THIN);
            }
        }

        if ($name !== null) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($from).$line, $name);
        }

        return $this;
    }

    public function addSheet(string $title): Worksheet
    {
        return $this->spreadsheet->addSheet(new Worksheet($this->spreadsheet, $title));
    }

    public function save(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'planning').'.xlsx';
        (new Xlsx($this->spreadsheet))->save($path);
        $this->files[] = $path;

        return $path;
    }

    public function contents(): string
    {
        return (string) file_get_contents($this->save());
    }

    public function cleanup(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
            @unlink(substr($file, 0, -5));
        }
    }
}
