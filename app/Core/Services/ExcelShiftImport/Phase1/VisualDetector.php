<?php

declare(strict_types=1);

namespace kintai\Core\Services\ExcelShiftImport\Phase1;

use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use kintai\Core\Services\ExcelShiftImport\DTOs\SegmentDTO;

/**
 * VisualDetector — Phase 1 : détection visuelle des segments
 *
 * Paramètres configurables (tous depuis les settings du store) :
 *   block_size         : nombre de lignes par bloc journalier (défaut : 9)
 *   shift_rows         : lignes de shifts dans chaque bloc (1 à N, défaut : 7)
 *   date_row_offsets   : ordre de recherche de la date dans le bloc (défaut : [2,0,1,3])
 *   sheet_filter_pattern : regex pour filtrer les feuilles valides (défaut : '^\d{4}$')
 */
final class VisualDetector
{
    private int   $blockSize;
    private int   $shiftRows;
    private array $dateRowOffsets;
    private string $sheetFilterPattern;

    public function __construct(
        private ColumnTimeCalculator $timeCalculator,
        private BorderDetector       $borderDetector,
        array $settings = []
    ) {
        $this->blockSize          = (int)   ($settings['block_size']          ?? 9);
        $this->shiftRows          = (int)   ($settings['shift_rows']          ?? 7);
        $this->dateRowOffsets     = (array) ($settings['date_row_offsets']    ?? [2, 0, 1, 3]);
        $this->sheetFilterPattern = (string)($settings['sheet_filter_pattern'] ?? '^\d{4}$');

        if ($this->blockSize < 2) $this->blockSize = 2;
        if ($this->shiftRows < 1) $this->shiftRows = 1;
        if (empty($this->dateRowOffsets)) $this->dateRowOffsets = [0];
    }

    /** @param Worksheet[] $sheets */
    public function detectSegments(array $sheets, ?int $targetYear = null, ?int $targetMonth = null): array
    {
        $all = [];
        foreach ($sheets as $sheet) {
            $all = array_merge($all, $this->detectFromSheet($sheet, $targetYear, $targetMonth));
        }
        // Propagation couleur→nom : une couleur = un employé
        return $this->propagateNamesByColor($all);
    }

    private function detectFromSheet(Worksheet $sheet, ?int $targetYear, ?int $targetMonth): array
    {
        $segments   = [];
        $highestRow = $sheet->getHighestRow();
        $sheetTitle = $sheet->getTitle();

        for ($blockStart = 1; $blockStart <= $highestRow; $blockStart += $this->blockSize) {
            $blockSegs = $this->detectFromBlock($sheet, $blockStart, $sheetTitle, $targetYear, $targetMonth);
            $segments  = array_merge($segments, $blockSegs);
        }

        return $segments;
    }

    private function detectFromBlock(
        Worksheet $sheet,
        int $blockStart,
        string $sheetTitle,
        ?int $targetYear,
        ?int $targetMonth
    ): array {
        $dateRaw = $this->findDateInBlock($sheet, $blockStart);
        if ($dateRaw === null) return [];

        $dateIso  = $this->toIsoDate($dateRaw, $targetYear, $targetMonth);
        $segments = [];

        for ($i = 1; $i <= $this->shiftRows; $i++) {
            $rowNum   = $blockStart + $i;
            $rowSegs  = $this->detectFromRow($sheet, $rowNum, $dateIso, [
                'sheet'       => $sheetTitle,
                'block_start' => $blockStart,
                'row_offset'  => $i,
            ]);
            $segments = array_merge($segments, $rowSegs);
        }

        return $segments;
    }

    private function detectFromRow(Worksheet $sheet, int $rowNum, string $dateIso, array $metadata): array
    {
        $segments       = [];
        $currentSegment = null;

        $colStart = $this->timeCalculator->getStartColumn();
        $colEnd   = $this->timeCalculator->getEndColumn();

        for ($col = $colStart; $col <= $colEnd; $col++) {
            $color         = $this->getCellColor($sheet, $col, $rowNum);
            $hasTop        = $this->borderDetector->hasTopBorder($sheet, $col, $rowNum);
            $hasBottom     = $this->borderDetector->hasBottomBorder($sheet, $col, $rowNum);
            $isSegmentCell = ($color !== null) || $hasTop || $hasBottom;

            if (!$isSegmentCell) {
                if ($currentSegment !== null) {
                    $segments[]     = $this->finalizeSegment($currentSegment, $col - 1, $dateIso, $metadata);
                    $currentSegment = null;
                }
                continue;
            }

            $segId = ($color ?? 'NC') . '|' . ($hasTop ? 'T' : '') . ($hasBottom ? 'B' : '');

            if ($currentSegment === null) {
                $currentSegment = [
                    'start_col'        => $col,
                    'color'            => $color,
                    'has_top_border'   => $hasTop,
                    'has_bottom_border'=> $hasBottom,
                    'segment_id'       => $segId,
                    'employee_name'    => $this->resolveText($sheet, $col, $rowNum),
                    'row_num'          => $rowNum,
                ];
            } elseif ($currentSegment['segment_id'] !== $segId) {
                $segments[]     = $this->finalizeSegment($currentSegment, $col - 1, $dateIso, $metadata);
                $currentSegment = [
                    'start_col'        => $col,
                    'color'            => $color,
                    'has_top_border'   => $hasTop,
                    'has_bottom_border'=> $hasBottom,
                    'segment_id'       => $segId,
                    'employee_name'    => $this->resolveText($sheet, $col, $rowNum),
                    'row_num'          => $rowNum,
                ];
            } else {
                // Même segment : si le nom n'est pas encore trouvé, chercher dans la cellule courante
                if ($currentSegment['employee_name'] === null) {
                    $currentSegment['employee_name'] = $this->resolveText($sheet, $col, $rowNum);
                }
            }
        }

        if ($currentSegment !== null) {
            $segments[] = $this->finalizeSegment($currentSegment, $colEnd, $dateIso, $metadata);
        }

        return $segments;
    }

    private function finalizeSegment(array $seg, int $endCol, string $dateIso, array $metadata): SegmentDTO
    {
        $range = $this->timeCalculator->calculateRange($seg['start_col'], $endCol);

        return new SegmentDTO(
            rowNumber:       $seg['row_num'],
            date:            $dateIso,
            startCol:        $seg['start_col'],
            endCol:          $endCol,
            startTime:       $range['start'],
            endTime:         $range['end'],
            durationMinutes: $range['duration_minutes'],
            cellCount:       $range['cell_count'],
            color:           $seg['color'],
            hasTopBorder:    $seg['has_top_border'],
            hasBottomBorder: $seg['has_bottom_border'],
            employeeName:    $seg['employee_name'],
            metadata:        $metadata
        );
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function findDateInBlock(Worksheet $sheet, int $blockStart): ?string
    {
        foreach ($this->dateRowOffsets as $offset) {
            $value = $this->getCellValue($sheet, 1, $blockStart + (int) $offset);
            if ($value !== null && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }
        return null;
    }

    private function toIsoDate(string $raw, ?int $targetYear, ?int $targetMonth): string
    {
        if (is_numeric($raw)) {
            try {
                $dt      = ExcelDate::excelToDateTimeObject((float) $raw);
                $dateIso = $dt->format('Y-m-d');
            } catch (\Exception) {
                $dateIso = date('Y-m-d');
            }
        } else {
            $ts      = strtotime($raw);
            $dateIso = $ts !== false ? date('Y-m-d', $ts) : date('Y-m-d');
        }

        if ($targetYear !== null && $targetMonth !== null) {
            [, , $day] = explode('-', $dateIso);
            $dateIso   = sprintf('%04d-%02d-%s', $targetYear, $targetMonth, $day);
        }

        return $dateIso;
    }

    /**
     * Lit le texte d'une cellule et filtre les valeurs purement numériques
     * (ex : les heures de la timeline 6, 7, 8…).
     * Le nom de l'employé est directement dans la cellule colorée du shift.
     */
    private function resolveText(Worksheet $sheet, int $col, int $row): ?string
    {
        $text = $this->getCellText($sheet, $col, $row);
        if ($text === null) return null;
        // Trim étendu : espaces ASCII + NBSP + espace pleine largeur japonais (U+3000)
        $text = rtrim($text, " \t\n\r\0\x0B\u{00A0}\u{3000}");
        if ($text === '') return null;
        // Ignorer les valeurs purement numériques (heures de la timeline)
        if (preg_match('/^\d+$/', $text)) return null;
        return $text;
    }

    /**
     * Propagation couleur→nom : une couleur identifie un employé.
     * Pour les segments colorés sans nom (cellule fusionnée lue partiellement),
     * on utilise le nom le plus fréquent associé à la même couleur dans toute la feuille.
     *
     * @param SegmentDTO[] $segments
     * @return SegmentDTO[]
     */
    private function propagateNamesByColor(array $segments): array
    {
        // Compter les occurrences nom par couleur
        $colorNameFreq = [];
        foreach ($segments as $seg) {
            if ($seg->color === null || $seg->employeeName === null) continue;
            $colorNameFreq[$seg->color][$seg->employeeName]
                = ($colorNameFreq[$seg->color][$seg->employeeName] ?? 0) + 1;
        }

        // Choisir le nom le plus fréquent pour chaque couleur
        $colorToName = [];
        foreach ($colorNameFreq as $color => $names) {
            arsort($names);
            $colorToName[$color] = (string) array_key_first($names);
        }

        if (empty($colorToName)) return $segments;

        return array_map(function (SegmentDTO $seg) use ($colorToName): SegmentDTO {
            // Segment déjà nommé ou sans couleur → rien à faire
            if ($seg->employeeName !== null || $seg->color === null) return $seg;
            $name = $colorToName[$seg->color] ?? null;
            if ($name === null) return $seg;

            return new SegmentDTO(
                rowNumber:       $seg->rowNumber,
                date:            $seg->date,
                startCol:        $seg->startCol,
                endCol:          $seg->endCol,
                startTime:       $seg->startTime,
                endTime:         $seg->endTime,
                durationMinutes: $seg->durationMinutes,
                cellCount:       $seg->cellCount,
                color:           $seg->color,
                hasTopBorder:    $seg->hasTopBorder,
                hasBottomBorder: $seg->hasBottomBorder,
                employeeName:    $name,
                metadata:        $seg->metadata
            );
        }, $segments);
    }

    private function getCellColor(Worksheet $sheet, int $col, int $row): ?string
    {
        if ($col < 1 || $row < 1) return null;

        $coord    = Coordinate::stringFromColumnIndex($col) . $row;
        $fill     = $sheet->getCell($coord)->getStyle()->getFill();
        $fillType = $fill->getFillType();

        if ($fillType === null || $fillType === '' || stripos((string) $fillType, 'none') !== false) {
            return null;
        }

        $argb = $fill->getStartColor()->getARGB();
        if ($argb === null || $argb === '' || $argb === '00000000' || $argb === 'FFFFFFFF') {
            return null;
        }

        return strtoupper($argb);
    }

    private function getCellValue(Worksheet $sheet, int $col, int $row): mixed
    {
        if ($col < 1 || $row < 1) return null;
        $coord = Coordinate::stringFromColumnIndex($col) . $row;
        try {
            return $sheet->getCell($coord)->getCalculatedValue();
        } catch (\Exception) {
            return $sheet->getCell($coord)->getValue();
        }
    }

    private function getCellText(Worksheet $sheet, int $col, int $row): ?string
    {
        $value = $this->getCellValue($sheet, $col, $row);
        if ($value === null || $value === '') return null;

        $text = str_replace("\u{00A0}", ' ', (string) $value);
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        return $text !== '' ? $text : null;
    }
}
