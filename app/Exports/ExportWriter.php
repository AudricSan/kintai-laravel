<?php

namespace App\Exports;

use InvalidArgumentException;
use Kintai\Contracts\Exports\ExportColumn;
use Kintai\Contracts\Exports\ExportDataset;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Écrit les lignes d'un jeu de données dans le format demandé, au fil de l'eau pour le CSV. Les colonnes viennent du
 * jeu de données ; une clé manquante dans une ligne vaut null, une clé en trop est ignorée.
 */
class ExportWriter
{
    public const CSV = 'csv';

    public const XLSX = 'xlsx';

    public const PDF = 'pdf';

    public const ICS = 'ics';

    /** @var list<string> */
    public const FORMATS = [self::CSV, self::XLSX, self::PDF, self::ICS];

    /** Lignes au plus par export (comme le journal d'activité). */
    public const MAX_ROWS = 20000;

    /**
     * Excel et PDF se construisent en mémoire : leur plafond est plus bas (mesuré : ~110 Mo pour 10 000 lignes Excel de
     * 10 colonnes, ~110 Mo pour 2 000 lignes PDF ; avec 128 Mo de mémoire PHP, 1 000 lignes PDF passent).
     */
    public const MAX_ROWS_XLSX = 10000;

    public const MAX_ROWS_PDF = 1000;

    public const CONTENT_TYPES = [
        self::CSV => 'text/csv; charset=UTF-8',
        self::XLSX => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        self::PDF => 'application/pdf',
        self::ICS => 'text/calendar; charset=utf-8',
    ];

    public static function maxRows(string $format): int
    {
        return match ($format) {
            self::PDF => self::MAX_ROWS_PDF,
            self::XLSX => self::MAX_ROWS_XLSX,
            default => self::MAX_ROWS,
        };
    }

    /**
     * @param  iterable<array<string, scalar|null>>  $rows
     */
    public function response(ExportDataset $dataset, iterable $rows, string $format, string $filename, string $subtitle = ''): StreamedResponse
    {
        $headers = ['Content-Type' => self::CONTENT_TYPES[$format] ?? throw new InvalidArgumentException("Unknown export format [{$format}].")];

        return response()->streamDownload(function () use ($dataset, $rows, $format, $subtitle): void {
            match ($format) {
                self::CSV => $this->csv($dataset, $rows),
                self::XLSX => $this->xlsx($dataset, $rows),
                self::PDF => $this->pdf($dataset, $rows, $subtitle),
                default => throw new InvalidArgumentException("Unsupported export format [{$format}]."),
            };
        }, $filename, $headers);
    }

    /**
     * Texte d'une valeur pour le CSV et le PDF.
     */
    public static function text(ExportColumn $column, mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return match (true) {
            is_bool($value) => $value ? __('Yes') : __('No'),
            $column->type === ExportColumn::DECIMAL && is_numeric($value) => number_format((float) $value, 2, '.', ''),
            default => is_scalar($value) ? (string) $value : '',
        };
    }

    /**
     * Un texte qui commence par = + - @ (ou une tabulation / un retour chariot) serait lu comme une formule par le tableur :
     * il est précédé d'une apostrophe. Les colonnes numériques ne sont pas touchées (un négatif reste un nombre).
     */
    public static function csvCell(ExportColumn $column, mixed $value): string
    {
        $text = self::text($column, $value);

        if (! $column->isNumeric() && preg_match('/^[=+\-@\t\r]/', $text) === 1) {
            return "'".$text;
        }

        return $text;
    }

    /**
     * @param  iterable<array<string, scalar|null>>  $rows
     */
    private function csv(ExportDataset $dataset, iterable $rows): void
    {
        $columns = $dataset->columns();
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");

        $header = new ExportColumn('header', '');
        $this->csvLine($out, array_map(fn (ExportColumn $c): string => self::csvCell($header, __($c->label)), $columns));

        $written = 0;
        foreach ($rows as $row) {
            if ($written++ >= self::MAX_ROWS) {
                break;
            }

            $this->csvLine($out, array_map(fn (ExportColumn $c): string => self::csvCell($c, $row[$c->key] ?? null), $columns));
        }

        fclose($out);
    }

    /**
     * @param  resource  $out
     * @param  list<string>  $cells
     */
    private function csvLine($out, array $cells): void
    {
        fputcsv($out, $cells, ';', '"', '');
    }

    /**
     * @param  iterable<array<string, scalar|null>>  $rows
     */
    private function xlsx(ExportDataset $dataset, iterable $rows): void
    {
        set_time_limit(180);
        $columns = $dataset->columns();
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle(mb_substr((string) preg_replace('/[\\\\\/?*\[\]:]/', ' ', __($dataset->label())), 0, 31) ?: 'Export');

        foreach ($columns as $index => $column) {
            $sheet->setCellValueExplicit([$index + 1, 1], __($column->label), DataType::TYPE_STRING);
        }
        $sheet->getStyle([1, 1, max(1, count($columns)), 1])->getFont()->setBold(true);
        $sheet->getStyle([1, 1, max(1, count($columns)), 1])->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        // En-têtes figés : ils restent visibles quand on fait défiler.
        $sheet->freezePane('A2');

        $line = 1;
        foreach ($rows as $row) {
            if ($line > self::MAX_ROWS_XLSX) {
                break;
            }

            $line++;
            foreach ($columns as $index => $column) {
                $this->xlsxCell($sheet, $index + 1, $line, $column, $row[$column->key] ?? null);
            }
        }

        foreach ($columns as $index => $column) {
            $sheet->getColumnDimensionByColumn($index + 1)->setAutoSize(true);

            if ($line > 1 && $column->type === ExportColumn::DATE) {
                $sheet->getStyle([$index + 1, 2, $index + 1, $line])->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_YYYYMMDD);
            } elseif ($line > 1 && $column->type === ExportColumn::DECIMAL) {
                $sheet->getStyle([$index + 1, 2, $index + 1, $line])->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_00);
            }
        }

        (new Xlsx($book))->save('php://output');
        $book->disconnectWorksheets();
    }

    /**
     * @param  Worksheet  $sheet
     */
    private function xlsxCell($sheet, int $column, int $line, ExportColumn $definition, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if ($definition->type === ExportColumn::DATE && is_string($value) && ($serial = Date::stringToExcel($value)) !== false) {
            $sheet->setCellValueExplicit([$column, $line], $serial, DataType::TYPE_NUMERIC);

            return;
        }

        if ($definition->isNumeric() && is_numeric($value)) {
            $sheet->setCellValueExplicit([$column, $line], $value + 0, DataType::TYPE_NUMERIC);

            return;
        }

        // Toujours du texte : un champ qui commence par « = » ne doit jamais devenir une formule.
        $sheet->setCellValueExplicit([$column, $line], self::text($definition, $value), DataType::TYPE_STRING);
    }

    /**
     * @param  iterable<array<string, scalar|null>>  $rows
     */
    private function pdf(ExportDataset $dataset, iterable $rows, string $subtitle): void
    {
        set_time_limit(180);
        $columns = $dataset->columns();

        $html = '<h2>'.e(__($dataset->label())).'</h2>';
        if ($subtitle !== '') {
            $html .= '<p class="sub">'.e($subtitle).'</p>';
        }
        $html .= '<table><thead><tr>';
        foreach ($columns as $column) {
            $html .= '<th>'.e(__($column->label)).'</th>';
        }
        $html .= '</tr></thead><tbody>';

        $written = 0;
        foreach ($rows as $row) {
            if ($written++ >= self::MAX_ROWS_PDF) {
                break;
            }

            $html .= '<tr>';
            foreach ($columns as $column) {
                $class = $column->isNumeric() ? ' class="num"' : '';
                $html .= '<td'.$class.'>'.e(self::text($column, $row[$column->key] ?? null)).'</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        $css = 'body{font-size:9pt}h2{font-size:14pt;margin:0 0 2mm}p.sub{color:#555;margin:0 0 4mm}'
            .'table{border-collapse:collapse;width:100%}th,td{border:0.2mm solid #999;padding:1mm 1.5mm;text-align:left}'
            .'th{background-color:#e4e4e7}td.num{text-align:right}';

        $directory = storage_path('app/mpdf');
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $defaults = (new ConfigVariables)->getDefaults();
        $fonts = (new FontVariables)->getDefaults();

        // Police DejaVu (latin, grec, cyrillique) ; le japonais passe à Sun-ExtA, fournie avec mPDF, grâce à la détection
        // d'écriture : aucune police système n'est requise.
        $pdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4-L',
            'margin_left' => 10,
            'margin_right' => 10,
            'margin_top' => 12,
            'margin_bottom' => 12,
            'tempDir' => $directory,
            'fontDir' => $defaults['fontDir'],
            'fontdata' => $fonts['fontdata'],
            'default_font' => 'dejavusans',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'simpleTables' => true,
            'packTableData' => true,
        ]);
        $pdf->SetTitle(__($dataset->label()));
        $pdf->WriteHTML($css, HTMLParserMode::HEADER_CSS);
        $pdf->WriteHTML($html, HTMLParserMode::HTML_BODY);

        echo $pdf->Output('', Destination::STRING_RETURN);
    }
}
