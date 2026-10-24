<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Export;

use kintai\Contract\Extension\ExportDataset;
use kintai\Contract\Provider\ExportQuery;
use kintai\Contract\Provider\ExportRowsProvider;
use kintai\Core\Export\ExportWriter;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;

/** Mise en forme d'un jeu de données : colonnes alignées, CSV sûr pour un tableur, JSON décrit, classeur Excel lisible. */
final class ExportWriterTest extends TestCase
{
    private ExportWriter $writer;
    private ExportDataset $dataset;

    protected function setUp(): void
    {
        $this->writer  = new ExportWriter();
        $this->dataset = new ExportDataset('demo', 'demo_label', 'shifts.view', ['name' => 'demo_name', 'hours' => 'demo_hours', 'active' => 'demo_active'], new class implements ExportRowsProvider {
            public function rows(ExportQuery $query): iterable
            {
                return [];
            }
        });
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return [
            ['name' => 'Aki', 'hours' => 7.5, 'active' => true, 'ignored' => 'x'],
            ['name' => '=HYPERLINK("http://x")', 'active' => false],
        ];
    }

    public function testTheTableFollowsTheColumnsAndFillsGapsWithNull(): void
    {
        $table = $this->writer->table($this->dataset, $this->rows());

        $this->assertSame(['name' => 'Aki', 'hours' => 7.5, 'active' => true], $table[0]);
        $this->assertSame(['name' => '=HYPERLINK("http://x")', 'hours' => null, 'active' => false], $table[1]);
    }

    public function testJsonDescribesTheDataset(): void
    {
        $response = $this->writer->response($this->dataset, $this->rows(), 'json', 'kintai-demo', ['stores' => [3]]);
        $data     = json_decode($response->body(), true);

        $this->assertStringContainsString('application/json', $response->headers()['Content-Type']);
        $this->assertStringContainsString('filename="kintai-demo.json"', $response->headers()['Content-Disposition']);
        $this->assertSame('demo', $data['dataset']);
        $this->assertSame([3], $data['stores']);
        $this->assertSame(['name', 'hours', 'active'], $data['columns']);
        $this->assertSame(2, $data['count']);
        $this->assertTrue($data['rows'][0]['active']);
    }

    public function testCsvHasABomTheTranslatedHeadersAndNeutralisedFormulas(): void
    {
        $response = $this->writer->response($this->dataset, $this->rows(), 'csv', 'kintai-demo');
        $lines    = explode("\r\n", $response->body());

        $this->assertStringContainsString('text/csv', $response->headers()['Content-Type']);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $response->body());
        // Sans traducteur, __() rend la clé : l'en-tête vient bien des clés de traduction des colonnes.
        $this->assertSame("\xEF\xBB\xBFdemo_name;demo_hours;demo_active", $lines[0]);
        $this->assertSame('Aki;7.5;1', $lines[1]);
        $this->assertSame('"\'=HYPERLINK(""http://x"")";;0', $lines[2]);
    }

    public function testXlsxIsAReadableWorkbookWhereFormulasStayText(): void
    {
        $response = $this->writer->response($this->dataset, $this->rows(), 'xlsx', 'kintai-demo');

        $this->assertSame(ExportWriter::XLSX_TYPE, $response->headers()['Content-Type']);
        $this->assertStringContainsString('filename="kintai-demo.xlsx"', $response->headers()['Content-Disposition']);

        $path = tempnam(sys_get_temp_dir(), 'kx') ?: '';
        file_put_contents($path, $response->body());
        try {
            $sheet = IOFactory::load($path)->getActiveSheet();
            $this->assertSame('demo_name', $sheet->getCell('A1')->getValue());
            $this->assertSame('Aki', $sheet->getCell('A2')->getValue());
            $this->assertEquals(7.5, $sheet->getCell('B2')->getValue());
            $this->assertSame('=HYPERLINK("http://x")', $sheet->getCell('A3')->getValue());
            $this->assertSame('s', $sheet->getCell('A3')->getDataType(), 'un texte commençant par = ne doit pas être une formule');
        } finally {
            @unlink($path);
        }
    }

    public function testAnUnknownFormatIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->writer->response($this->dataset, [], 'xml', 'kintai-demo');
    }

    public function testTheFilenameIsSanitised(): void
    {
        $response = $this->writer->response($this->dataset, [], 'csv', 'a b/../c"d');

        $this->assertStringContainsString('filename="a_b_.._c_d.csv"', $response->headers()['Content-Disposition']);
    }
}
