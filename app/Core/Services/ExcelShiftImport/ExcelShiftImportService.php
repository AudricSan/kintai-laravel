<?php

declare(strict_types=1);

namespace kintai\Core\Services\ExcelShiftImport;

use PhpOffice\PhpSpreadsheet\IOFactory;
use kintai\Core\Services\ExcelShiftImport\Phase1\ColumnTimeCalculator;
use kintai\Core\Services\ExcelShiftImport\Phase1\BorderDetector;
use kintai\Core\Services\ExcelShiftImport\Phase1\VisualDetector;
use kintai\Core\Services\ExcelShiftImport\Phase2\WhiteBackgroundRule;
use kintai\Core\Services\ExcelShiftImport\Phase2\ConfidenceScorer;
use kintai\Core\Services\ExcelShiftImport\Phase2\BusinessQualifier;
use kintai\Core\Services\ExcelShiftImport\Phase3\DeduplicationKey;
use kintai\Core\Services\ExcelShiftImport\Phase3\StrictDeduplicator;
use kintai\Core\Services\ExcelShiftImport\DTOs\SegmentDTO;

/**
 * ExcelShiftImportService — orchestrateur des phases 1-3
 *
 * Toutes les constantes de grille sont paramétrables via $settings.
 * Les valeurs par défaut correspondent au format planning japonais standard.
 *
 * Settings disponibles (tous optionnels) :
 *   col_start            : int    — première colonne de données (défaut : 4 = col D)
 *   col_end              : int    — dernière colonne (défaut : 52 = col AZ)
 *   base_hour            : int    — heure de départ (défaut : 6 → 06:00)
 *   minutes_per_col      : int    — minutes par colonne (défaut : 30)
 *   block_size           : int    — lignes par bloc jour (défaut : 9)
 *   shift_rows           : int    — lignes de shifts dans le bloc (défaut : 7)
 *   date_row_offsets     : int[]  — ordre de recherche date dans le bloc (défaut : [2,0,1,3])
 *   sheet_filter_pattern : string — regex feuilles valides (défaut : '^\d{4}$')
 *
 * Note : min_shift_minutes et max_shift_minutes sont des paramètres magasin
 * appliqués après le scan (dans le contrôleur), pas pendant la détection visuelle.
 * Seul le bruit visuel sous SCAN_NOISE_MIN_MINUTES est filtré ici.
 */
final class ExcelShiftImportService
{
    /** Seuil fixe de bruit visuel : segments plus courts ignorés pendant le scan */
    private const SCAN_NOISE_MIN_MINUTES = 30;

    /** Valeurs par défaut (format planning japonais) */
    public const DEFAULTS = [
        'col_start'            => 4,
        'col_end'              => 52,
        'base_hour'            => 6,
        'minutes_per_col'      => 30,
        'block_size'           => 9,
        'shift_rows'           => 7,
        'date_row_offsets'     => [2, 0, 1, 3],
        'sheet_filter_pattern' => '^\d{4}$',
        'auto_pause_after_minutes'  => 0,   // 0 = désactivé ; ex : 360 = pause si shift > 6h
        'auto_pause_minutes'        => 30,  // durée de la pause automatique en minutes
    ];

    private VisualDetector     $visualDetector;
    private BusinessQualifier  $qualifier;
    private StrictDeduplicator $deduplicator;
    private string             $sheetFilterPattern;

    public function __construct(array $settings = [])
    {
        $cfg = array_merge(self::DEFAULTS, $settings);

        $this->sheetFilterPattern = (string) ($cfg['sheet_filter_pattern'] ?? self::DEFAULTS['sheet_filter_pattern']);

        $timeCalc             = new ColumnTimeCalculator($cfg);
        $borderDet            = new BorderDetector();
        $this->visualDetector = new VisualDetector($timeCalc, $borderDet, $cfg);

        $this->qualifier    = new BusinessQualifier(new WhiteBackgroundRule(), new ConfidenceScorer());
        $this->deduplicator = new StrictDeduplicator(new DeduplicationKey());
    }

    /**
     * Analyse un fichier Excel et retourne les shifts détectés.
     *
     * @param string $filePath         Chemin temporaire du fichier uploadé
     * @param string $originalFileName Nom original (pour extraction année/mois)
     * @return array<array{date:string, staff_name:string, start_time:string, end_time:string, hours:float}>
     * @throws \RuntimeException
     */
    public function parse(string $filePath, string $originalFileName = ''): array
    {
        if (!is_file($filePath)) {
            throw new \RuntimeException('Fichier Excel introuvable : ' . $filePath);
        }

        try {
            $spreadsheet = IOFactory::load($filePath);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Impossible de lire le fichier Excel : ' . $e->getMessage(), 0, $e);
        }

        // Extraction année/mois depuis le nom de fichier (format : YYYY年MM月)
        $targetYear = $targetMonth = null;
        if (preg_match('/(\d{4})年(\d{1,2})月/', $originalFileName, $m)) {
            $targetYear  = (int) $m[1];
            $targetMonth = (int) $m[2];
        }

        // Filtrage des feuilles selon le pattern configuré
        $allSheets = $spreadsheet->getAllSheets();
        $pattern   = $this->sheetFilterPattern;
        $sheets    = array_values(array_filter($allSheets, fn($s) => preg_match('/' . $pattern . '/', $s->getTitle())));

        // Fallback : toutes les feuilles si aucune ne correspond au pattern
        if (empty($sheets)) {
            $sheets = $allSheets;
        }

        if (empty($sheets)) {
            throw new \RuntimeException('Aucune feuille valide trouvée dans le fichier Excel.');
        }

        // Phase 1 — Détection visuelle
        $segments = $this->visualDetector->detectSegments($sheets, $targetYear, $targetMonth);

        if (empty($segments)) {
            return [];
        }

        // Fusion et filtre du bruit visuel (segments sous SCAN_NOISE_MIN_MINUTES)
        $segments = $this->mergeShortSegments($segments);

        if (empty($segments)) {
            return [];
        }

        // Phase 2 — Qualification métier
        $qualified = $this->qualifier->qualify($segments);

        // Phase 3 — Déduplication
        $deduplicated = $this->deduplicator->deduplicate($qualified);

        // Conversion vers le format compatible avec la prévisualisation existante
        $results = [];
        foreach ($deduplicated as $shift) {
            $name = trim($shift->getEmployeeName() ?? '');
            if ($name === '') continue;

            $results[] = [
                'date'            => $shift->getDate(),
                'staff_name'      => $name,
                'start_time'      => $shift->getStartTime(),
                'end_time'        => $shift->getEndTime(),
                'hours'           => round($shift->segment->durationMinutes / 60, 1),
                'duplicate_count' => $shift->duplicateCount,
            ];
        }

        return $results;
    }

    /**
     * Fusionne ou ignore les segments dont la durée est inférieure à $minShiftMinutes.
     *
     * Règle : si un segment court est encadré par deux segments de même couleur
     * sur la même ligne (même date + rowNumber), les trois sont fusionnés en un seul.
     * Sinon, le segment court est simplement supprimé.
     *
     * @param \kintai\Core\Services\ExcelShiftImport\DTOs\SegmentDTO[] $segments
     * @return \kintai\Core\Services\ExcelShiftImport\DTOs\SegmentDTO[]
     */
    private function mergeShortSegments(array $segments): array
    {
        // Grouper par (date + rowNumber), segments déjà triés par start_col
        $groups = [];
        foreach ($segments as $seg) {
            $groups[$seg->date . '|' . $seg->rowNumber][] = $seg;
        }

        $result = [];
        foreach ($groups as $segs) {
            $result = array_merge($result, $this->processGroup($segs));
        }
        return $result;
    }

    /** @param SegmentDTO[] $segs */
    private function processGroup(array $segs): array
    {
        $n      = count($segs);
        $result = [];
        $i      = 0;

        while ($i < $n) {
            $seg = $segs[$i];

            if ($seg->durationMinutes >= self::SCAN_NOISE_MIN_MINUTES) {
                $result[] = $seg;
                $i++;
                continue;
            }

            // Segment court : tenter la fusion prev+curr+next (même couleur)
            $prev = count($result) > 0 ? $result[count($result) - 1] : null;
            $next = $segs[$i + 1] ?? null;

            if (
                $prev !== null
                && $next !== null
                && $prev->color !== null
                && $prev->color === $next->color
            ) {
                // Fusionner prev (déjà dans $result) + curr + next
                array_pop($result);
                $result[] = new SegmentDTO(
                    rowNumber:       $prev->rowNumber,
                    date:            $prev->date,
                    startCol:        $prev->startCol,
                    endCol:          $next->endCol,
                    startTime:       $prev->startTime,
                    endTime:         $next->endTime,
                    durationMinutes: $prev->durationMinutes + $seg->durationMinutes + $next->durationMinutes,
                    cellCount:       $prev->cellCount + $seg->cellCount + $next->cellCount,
                    color:           $prev->color,
                    hasTopBorder:    $prev->hasTopBorder,
                    hasBottomBorder: $next->hasBottomBorder,
                    employeeName:    $prev->employeeName ?? $next->employeeName,
                    metadata:        $prev->metadata
                );
                $i += 2; // sauter curr et next
            } else {
                // Segment court non fusionnable → ignorer
                $i++;
            }
        }

        return $result;
    }

}

