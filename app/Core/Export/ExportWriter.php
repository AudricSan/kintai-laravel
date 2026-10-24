<?php

declare(strict_types=1);

namespace kintai\Core\Export;

use kintai\Contract\Extension\ExportDataset;
use kintai\Core\Response;
use kintai\Core\Services\ScheduleExportService;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Met les lignes d'un jeu de données dans le format demandé : JSON, CSV ou classeur Excel. Les colonnes et leur ordre viennent
 * du jeu de données ; une clé manquante dans une ligne vaut null et une clé en trop est ignorée, quel que soit le fournisseur.
 */
final class ExportWriter
{
    public const FORMATS = ['json', 'csv', 'xlsx'];

    public const XLSX_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /**
     * @param iterable<array<string, scalar|null>> $rows
     * @param array<string, scalar|null>           $meta Informations du périmètre ajoutées au JSON (magasins, période…)
     */
    public function response(ExportDataset $dataset, iterable $rows, string $format, string $basename, array $meta = []): Response
    {
        $table = $this->table($dataset, $rows);
        $name  = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $basename);

        return match ($format) {
            'json'  => Response::jsonDownload($this->json($dataset, $table, $meta), $name . '.json'),
            'csv'   => Response::csv($this->csv($dataset, $table), $name . '.csv'),
            'xlsx'  => Response::download($this->xlsx($dataset, $table), self::XLSX_TYPE, $name . '.xlsx'),
            default => throw new \InvalidArgumentException("Format d'export inconnu : {$format}"),
        };
    }

    /**
     * Lignes alignées sur les colonnes du jeu de données.
     *
     * @param iterable<array<string, mixed>> $rows
     * @return list<array<string, scalar|null>>
     */
    public function table(ExportDataset $dataset, iterable $rows): array
    {
        $table = [];
        foreach ($rows as $row) {
            $line = [];
            foreach (array_keys($dataset->columns) as $column) {
                $value = $row[$column] ?? null;
                $line[$column] = is_scalar($value) ? $value : null;
            }
            $table[] = $line;
        }

        return $table;
    }

    /** @return list<string> En-têtes traduits, dans l'ordre des colonnes */
    public function headers(ExportDataset $dataset): array
    {
        return array_map(static fn(string $key): string => __($key), array_values($dataset->columns));
    }

    /**
     * @param list<array<string, scalar|null>> $table
     * @param array<string, scalar|null>       $meta
     * @return array<string, mixed>
     */
    private function json(ExportDataset $dataset, array $table, array $meta): array
    {
        return $meta + [
            'dataset'      => $dataset->key,
            'label'        => __($dataset->label),
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'columns'      => array_keys($dataset->columns),
            'count'        => count($table),
            'rows'         => $table,
        ];
    }

    /**
     * UTF-8 avec BOM, séparateur « ; » et CRLF, comme l'export de l'horaire : Excel l'ouvre sans assistant. Les cellules sont
     * neutralisées contre l'injection de formule.
     *
     * @param list<array<string, scalar|null>> $table
     */
    private function csv(ExportDataset $dataset, array $table): string
    {
        $cell  = static fn(mixed $v): string => ScheduleExportService::csvCell(is_bool($v) ? (int) $v : $v);
        $lines = [implode(';', array_map($cell, $this->headers($dataset)))];
        foreach ($table as $row) {
            $lines[] = implode(';', array_map($cell, $row));
        }

        return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
    }

    /** @param list<array<string, scalar|null>> $table */
    private function xlsx(ExportDataset $dataset, array $table): string
    {
        $book  = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle(mb_substr((string) preg_replace('/[\\\\\/?*\[\]:]/', ' ', __($dataset->label)), 0, 31) ?: 'Export');

        $col = 1;
        foreach ($this->headers($dataset) as $header) {
            $sheet->setCellValueExplicit([$col++, 1], $header, DataType::TYPE_STRING);
        }
        $sheet->getStyle([1, 1, max(1, $col - 1), 1])->getFont()->setBold(true);
        $sheet->freezePane('A2');

        $rowNumber = 2;
        foreach ($table as $row) {
            $col = 1;
            foreach ($row as $value) {
                if ($value === null || $value === '') {
                    $col++;
                } elseif (is_bool($value)) {
                    $sheet->setCellValueExplicit([$col++, $rowNumber], (int) $value, DataType::TYPE_NUMERIC);
                } elseif (is_int($value) || is_float($value)) {
                    $sheet->setCellValueExplicit([$col++, $rowNumber], $value, DataType::TYPE_NUMERIC);
                } else {
                    // Toujours du texte : un champ commençant par « = » ne doit jamais devenir une formule.
                    $sheet->setCellValueExplicit([$col++, $rowNumber], (string) $value, DataType::TYPE_STRING);
                }
            }
            $rowNumber++;
        }

        foreach (range(1, max(1, count($dataset->columns))) as $c) {
            $sheet->getColumnDimensionByColumn($c)->setAutoSize(true);
        }
        $sheet->getStyle([1, 1, max(1, count($dataset->columns)), 1])->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        ob_start();
        (new Xlsx($book))->save('php://output');
        $content = (string) ob_get_clean();
        $book->disconnectWorksheets();

        return $content;
    }
}
