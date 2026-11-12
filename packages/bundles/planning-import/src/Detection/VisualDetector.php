<?php

namespace Kintai\Bundles\PlanningImport\Detection;

use Exception;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Phase 1 : détection visuelle des segments. Le fichier est une suite de blocs de `blockSize` lignes (un jour par bloc) :
 * la date est en colonne A, les `shiftRows` lignes suivantes portent les shifts, une cellule colorée ou bordée appartenant
 * à un segment dont l'heure se déduit de la colonne. Le nom de l'employé est dans la cellule colorée.
 */
final class VisualDetector
{
    public function __construct(
        private readonly GridSettings $grid,
        private readonly ColumnTimeCalculator $times,
        private readonly BorderDetector $borders,
    ) {}

    /**
     * @param  list<Worksheet>  $sheets
     * @return list<Segment>
     */
    public function detect(array $sheets, ?int $year = null, ?int $month = null): array
    {
        $all = [];
        foreach ($sheets as $sheet) {
            $highest = $sheet->getHighestRow();
            for ($blockStart = 1; $blockStart <= $highest; $blockStart += $this->grid->blockSize) {
                array_push($all, ...$this->block($sheet, $blockStart, $year, $month));
            }
        }

        return $this->propagateNamesByColor($all);
    }

    /**
     * @return list<Segment>
     */
    private function block(Worksheet $sheet, int $blockStart, ?int $year, ?int $month): array
    {
        $raw = $this->dateIn($sheet, $blockStart);
        if ($raw === null) {
            return [];
        }

        $date = $this->isoDate($raw, $year, $month);
        $segments = [];
        for ($i = 1; $i <= $this->grid->shiftRows; $i++) {
            array_push($segments, ...$this->row($sheet, $blockStart + $i, $date, [
                'sheet' => $sheet->getTitle(), 'block_start' => $blockStart, 'row_offset' => $i,
            ]));
        }

        return $segments;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return list<Segment>
     */
    private function row(Worksheet $sheet, int $row, string $date, array $metadata): array
    {
        $segments = [];
        $current = null;

        for ($col = $this->grid->colStart; $col <= $this->grid->colEnd; $col++) {
            $color = $this->color($sheet, $col, $row);
            $top = $this->borders->hasTop($sheet, $col, $row);
            $bottom = $this->borders->hasBottom($sheet, $col, $row);

            if ($color === null && ! $top && ! $bottom) {
                if ($current !== null) {
                    $segments[] = $this->finish($current, $col - 1, $date, $metadata);
                    $current = null;
                }

                continue;
            }

            $id = ($color ?? 'NC').'|'.($top ? 'T' : '').($bottom ? 'B' : '');

            if ($current !== null && $current['id'] === $id) {
                // Même segment : le nom peut n'apparaître que dans une cellule suivante.
                $current['name'] ??= $this->text($sheet, $col, $row);

                continue;
            }

            if ($current !== null) {
                $segments[] = $this->finish($current, $col - 1, $date, $metadata);
            }

            $current = [
                'start' => $col, 'color' => $color, 'top' => $top, 'bottom' => $bottom, 'id' => $id,
                'name' => $this->text($sheet, $col, $row), 'row' => $row,
            ];
        }

        if ($current !== null) {
            $segments[] = $this->finish($current, $this->grid->colEnd, $date, $metadata);
        }

        return $segments;
    }

    /**
     * @param  array{start: int, color: ?string, top: bool, bottom: bool, id: string, name: ?string, row: int}  $segment
     * @param  array<string, mixed>  $metadata
     */
    private function finish(array $segment, int $endCol, string $date, array $metadata): Segment
    {
        $range = $this->times->range($segment['start'], $endCol);

        return new Segment(
            $segment['row'], $date, $segment['start'], $endCol, $range['start'], $range['end'], $range['duration_minutes'],
            $range['cell_count'], $segment['color'], $segment['top'], $segment['bottom'], $segment['name'], $metadata,
        );
    }

    private function dateIn(Worksheet $sheet, int $blockStart): ?string
    {
        foreach (GridSettings::DATE_ROW_OFFSETS as $offset) {
            $value = $this->value($sheet, 1, $blockStart + $offset);
            if ($value !== null && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    /**
     * Date ISO de la cellule ; si le nom du fichier donne l'année et le mois, seul le jour de la cellule est gardé.
     */
    private function isoDate(string $raw, ?int $year, ?int $month): string
    {
        if (is_numeric($raw)) {
            try {
                $date = ExcelDate::excelToDateTimeObject((float) $raw)->format('Y-m-d');
            } catch (Exception) {
                $date = date('Y-m-d');
            }
        } else {
            $timestamp = strtotime($raw);
            $date = $timestamp !== false ? date('Y-m-d', $timestamp) : date('Y-m-d');
        }

        if ($year !== null && $month !== null) {
            $date = sprintf('%04d-%02d-%s', $year, $month, substr($date, 8, 2));
        }

        return $date;
    }

    /**
     * Texte d'une cellule colorée, sans les valeurs purement numériques (les heures de la frise 6, 7, 8…).
     */
    private function text(Worksheet $sheet, int $col, int $row): ?string
    {
        $value = $this->value($sheet, $col, $row);
        if ($value === null || $value === '') {
            return null;
        }

        $text = trim(preg_replace('/\s+/', ' ', str_replace("\u{00A0}", ' ', (string) $value)) ?? '');
        // Espaces, insécable et pleine largeur japonaise (U+3000) en fin de texte (par caractère : un rtrim par octets
        // couperait un nom qui finit par « む », dont le dernier octet est 0x80).
        $text = preg_replace('/[\s\x{00A0}\x{3000}]+$/u', '', $text) ?? $text;

        return $text === '' || preg_match('/^\d+$/', $text) ? null : $text;
    }

    /**
     * Une couleur identifie un employé : un segment coloré sans nom (cellule fusionnée lue partiellement) prend le nom le
     * plus fréquent de sa couleur dans tout le fichier.
     *
     * @param  list<Segment>  $segments
     * @return list<Segment>
     */
    private function propagateNamesByColor(array $segments): array
    {
        $frequency = [];
        foreach ($segments as $segment) {
            if ($segment->color !== null && $segment->employeeName !== null) {
                $frequency[$segment->color][$segment->employeeName] = ($frequency[$segment->color][$segment->employeeName] ?? 0) + 1;
            }
        }

        $names = [];
        foreach ($frequency as $color => $counts) {
            arsort($counts);
            $names[$color] = (string) array_key_first($counts);
        }

        return array_map(
            fn (Segment $segment): Segment => $segment->employeeName !== null || $segment->color === null || ! isset($names[$segment->color])
                ? $segment
                : $segment->withName($names[$segment->color]),
            $segments,
        );
    }

    private function color(Worksheet $sheet, int $col, int $row): ?string
    {
        $fill = $sheet->getCell(Coordinate::stringFromColumnIndex($col).$row)->getStyle()->getFill();
        $type = (string) $fill->getFillType();

        if ($type === '' || stripos($type, 'none') !== false) {
            return null;
        }

        $argb = $fill->getStartColor()->getARGB();

        return $argb === '' || $argb === '00000000' || $argb === 'FFFFFFFF' ? null : strtoupper((string) $argb);
    }

    private function value(Worksheet $sheet, int $col, int $row): mixed
    {
        $cell = $sheet->getCell(Coordinate::stringFromColumnIndex($col).$row);

        try {
            return $cell->getCalculatedValue();
        } catch (Exception) {
            return $cell->getValue();
        }
    }
}
