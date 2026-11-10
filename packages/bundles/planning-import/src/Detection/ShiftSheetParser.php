<?php

namespace Kintai\Bundles\PlanningImport\Detection;

use Kintai\Bundles\PlanningImport\ImportException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * Lit un fichier de planning Excel et renvoie les shifts détectés, en trois phases : détection visuelle (couleurs,
 * bordures, grille horaire), qualification métier avec score de confiance, déduplication stricte. La grille vient des
 * réglages du magasin (`GridSettings`).
 */
final class ShiftSheetParser
{
    /** Bruit visuel : les segments plus courts (en minutes) sont fusionnés ou ignorés pendant la lecture. */
    private const NOISE_MINUTES = 30;

    public function __construct(private readonly GridSettings $grid = new GridSettings) {}

    /**
     * L'année et le mois viennent du nom du fichier (« 2026年11月 ») quand il les donne ; la date de chaque bloc ne garde
     * alors que son jour.
     *
     * @return list<array{date: string, staff_name: string, start_time: string, end_time: string, hours: float, duplicate_count: int}>
     *
     * @throws ImportException
     */
    public function parse(string $path, string $originalName = ''): array
    {
        if (! is_file($path)) {
            throw new ImportException('Spreadsheet not found.');
        }

        try {
            $spreadsheet = IOFactory::load($path);
        } catch (Throwable $e) {
            throw new ImportException('The spreadsheet cannot be read.', 0, $e);
        }

        $year = $month = null;
        if (preg_match('/(\d{4})年(\d{1,2})月/u', $originalName, $m)) {
            $year = (int) $m[1];
            $month = (int) $m[2];
        }

        $all = array_values($spreadsheet->getAllSheets());
        $regex = GridSettings::regex($this->grid->sheetPattern);
        $sheets = $regex === null ? [] : array_values(array_filter($all, fn ($sheet): bool => preg_match($regex, $sheet->getTitle()) === 1));
        // Aucune feuille ne correspond au motif : toutes sont lues.
        $sheets = $sheets === [] ? $all : $sheets;

        $detector = new VisualDetector($this->grid, new ColumnTimeCalculator($this->grid), new BorderDetector);
        $segments = $this->mergeShortSegments($detector->detect($sheets, $year, $month));

        $results = [];
        foreach ((new StrictDeduplicator)->deduplicate((new BusinessQualifier)->qualify($segments)) as $shift) {
            $name = trim($shift->segment->employeeName ?? '');
            if ($name === '') {
                continue;
            }

            $results[] = [
                'date' => $shift->segment->date,
                'staff_name' => $name,
                'start_time' => $shift->segment->startTime,
                'end_time' => $shift->segment->endTime,
                'hours' => round($shift->segment->durationMinutes / 60, 1),
                'duplicate_count' => $shift->duplicates,
            ];
        }

        return $results;
    }

    /**
     * Un segment court encadré par deux segments de même couleur sur la même ligne et le même jour fusionne avec eux ;
     * sinon il est supprimé.
     *
     * @param  list<Segment>  $segments
     * @return list<Segment>
     */
    private function mergeShortSegments(array $segments): array
    {
        $groups = [];
        foreach ($segments as $segment) {
            $groups[$segment->date.'|'.$segment->rowNumber][] = $segment;
        }

        $result = [];
        foreach ($groups as $group) {
            array_push($result, ...$this->mergeGroup($group));
        }

        return $result;
    }

    /**
     * @param  list<Segment>  $segments
     * @return list<Segment>
     */
    private function mergeGroup(array $segments): array
    {
        $result = [];
        $count = count($segments);

        for ($i = 0; $i < $count; $i++) {
            $segment = $segments[$i];

            if ($segment->durationMinutes >= self::NOISE_MINUTES) {
                $result[] = $segment;

                continue;
            }

            $previous = $result === [] ? null : $result[count($result) - 1];
            $next = $segments[$i + 1] ?? null;

            if ($previous === null || $next === null || $previous->color === null || $previous->color !== $next->color) {
                continue;
            }

            array_pop($result);
            $result[] = new Segment(
                $previous->rowNumber, $previous->date, $previous->startCol, $next->endCol, $previous->startTime, $next->endTime,
                $previous->durationMinutes + $segment->durationMinutes + $next->durationMinutes,
                $previous->cellCount + $segment->cellCount + $next->cellCount,
                $previous->color, $previous->hasTopBorder, $next->hasBottomBorder,
                $previous->employeeName ?? $next->employeeName, $previous->metadata,
            );
            $i++;
        }

        return $result;
    }
}
