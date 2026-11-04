<?php

namespace Tests\Feature\Exports;

use App\Exports\AbstractExportDataset;
use App\Exports\ExportColumn;
use App\Exports\ExportDatasetRegistry;
use App\Exports\ExportScope;
use App\Exports\ExportWriter;
use App\Models\ActivityEntry;
use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\Concerns\CreatesAccess;
use Tests\TestCase;

class ExportDownloadTest extends TestCase
{
    use CreatesAccess;
    use RefreshDatabase;

    private Store $store;

    private User $manager;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->store = Store::factory()->create(['name' => 'Alpha']);
        $this->manager = $this->managerOf($this->store);
        $this->employee = $this->employeeOf($this->store, ['name' => 'Anna']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function shift(User $user, string $date, array $attributes = []): Shift
    {
        return Shift::factory()->create($attributes + [
            'user_id' => $user->id,
            'store_id' => $this->store->id,
            'shift_date' => $date,
            'starts_at' => Shift::localTime($date, '09:00'),
            'ends_at' => Shift::localTime($date, '17:00'),
            'pause_minutes' => 60,
        ]);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function download(array $parameters): TestResponse
    {
        return $this->get(route('exports.download', $parameters + ['dataset' => 'shifts', 'format' => 'csv', 'from' => '2026-11-01', 'to' => '2026-11-30']));
    }

    public function test_csv_has_a_bom_semicolons_translated_headers_and_neutralized_formulas(): void
    {
        $this->shift($this->employee, '2026-11-10', ['notes' => '=HYPERLINK("http://evil")']);
        $this->shift($this->employee, '2026-11-11', ['notes' => '-5+3']);
        $this->shift($this->employee, '2026-11-12', ['notes' => 'say "hi"; bye']);

        $response = $this->actingAs($this->manager)->download(['target' => 'stores']);

        $response->assertOk();
        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('kintai-shifts-2026-11-01_2026-11-30.csv', $response->headers->get('Content-Disposition'));
        $body = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString(';Alpha;', $body);
        $lines = array_map(fn (string $line): array => str_getcsv($line, ';', '"', ''), preg_split('/\r?\n/', trim(substr($body, 3))));
        $this->assertSame(['Date', 'Start', 'End', 'Ends the next day', 'Break (min)', 'Net minutes', 'Shift type', 'Employee', 'Store', 'Notes'], $lines[0]);
        $this->assertSame(['2026-11-10', '09:00', '17:00', 'No', '60', '420', '', 'Anna', 'Alpha', "'=HYPERLINK(\"http://evil\")"], $lines[1]);
        $this->assertSame("'-5+3", $lines[2][9]);
        $this->assertSame('say "hi"; bye', $lines[3][9]);
    }

    public function test_csv_headers_follow_the_user_language(): void
    {
        $this->manager->forceFill(['locale' => 'fr'])->save();
        $this->shift($this->employee, '2026-11-10');

        $body = $this->actingAs($this->manager)->download(['target' => 'stores'])->streamedContent();

        $this->assertStringContainsString('Pause (min)', $body);
        $this->assertStringContainsString(';Non;', $body);
    }

    public function test_numbers_are_not_neutralized_in_csv(): void
    {
        $writerOutput = ExportWriter::csvCell(new ExportColumn('n', 'N', ExportColumn::INTEGER), -5);
        $text = ExportWriter::csvCell(new ExportColumn('t', 'T'), '+81 3 1234');

        $this->assertSame('-5', $writerOutput);
        $this->assertSame("'+81 3 1234", $text);
    }

    public function test_excel_is_readable_with_typed_cells_frozen_headers_and_text_formulas(): void
    {
        $this->shift($this->employee, '2026-11-10', ['notes' => '=1+1']);

        $response = $this->actingAs($this->manager)->download(['target' => 'stores', 'format' => 'xlsx']);

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml.sheet', $response->headers->get('Content-Type'));
        $sheet = $this->readSheet($response->streamedContent());

        $this->assertSame('Date', $sheet->getCell('A1')->getValue());
        $this->assertSame('Net minutes', $sheet->getCell('F1')->getValue());
        $this->assertSame('A2', $sheet->getFreezePane());
        $this->assertTrue($sheet->getStyle('A1')->getFont()->getBold());
        // Date : vraie date Excel formatée.
        $date = $sheet->getCell('A2');
        $this->assertSame(Date::stringToExcel('2026-11-10'), (float) $date->getValue());
        $this->assertSame(NumberFormat::FORMAT_DATE_YYYYMMDD, $date->getStyle()->getNumberFormat()->getFormatCode());
        // Nombres : numériques.
        $this->assertSame(420, $sheet->getCell('F2')->getValue());
        $this->assertSame(60, $sheet->getCell('E2')->getValue());
        // Texte : jamais une formule.
        $this->assertSame('09:00', $sheet->getCell('B2')->getValue());
        $this->assertSame('Anna', $sheet->getCell('H2')->getValue());
        $this->assertSame('=1+1', $sheet->getCell('J2')->getValue());
        $this->assertSame('s', $sheet->getCell('J2')->getDataType());
    }

    public function test_excel_payroll_has_decimal_amounts(): void
    {
        $this->shift($this->employee, '2026-11-10', ['hourly_rate_override' => 12.5, 'net_minutes_override' => 360]);

        $response = $this->actingAs($this->manager)->download(['dataset' => 'payroll', 'target' => 'stores', 'format' => 'xlsx']);

        $sheet = $this->readSheet($response->streamedContent());
        $this->assertSame('Estimated amount', $sheet->getCell('G1')->getValue());
        $this->assertEqualsWithDelta(75.0, $sheet->getCell('G2')->getValue(), 0.001);
        $this->assertSame(NumberFormat::FORMAT_NUMBER_00, $sheet->getCell('G2')->getStyle()->getNumberFormat()->getFormatCode());
    }

    public function test_pdf_is_a_non_empty_landscape_pdf_that_handles_japanese(): void
    {
        $japanese = User::factory()->create(['name' => '山田 太郎']);
        $this->store->members()->attach($japanese);
        $this->shift($japanese, '2026-11-10', ['notes' => 'シフトのメモ']);

        $response = $this->actingAs($this->manager)->download(['target' => 'stores', 'format' => 'pdf']);

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $pdf = $response->streamedContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(2000, strlen($pdf));
        // Page A4 paysage (842 x 595 pt) et police CJK embarquée.
        $this->assertMatchesRegularExpression('#/MediaBox\s*\[\s*0(\.0+)?\s+0(\.0+)?\s+841\.8\d*\s+595\.2\d*\s*\]#', $pdf);
        $this->assertStringContainsString('Sun-ExtA', $pdf);
    }

    public function test_an_export_above_the_row_limit_is_refused_with_a_clear_message(): void
    {
        $this->bigDataset(ExportWriter::MAX_ROWS + 1);

        $this->actingAs($this->manager)->download(['dataset' => 'big', 'target' => 'stores'])
            ->assertRedirect(route('exports.index'))
            ->assertSessionHasErrors('export');
        $this->assertSame(0, ActivityEntry::where('action', 'export.downloaded')->count());
    }

    public function test_the_row_limit_is_reached_but_not_exceeded(): void
    {
        $this->bigDataset(ExportWriter::MAX_ROWS);

        $this->actingAs($this->manager)->download(['dataset' => 'big', 'target' => 'stores'])->assertOk();
        $this->assertSame(1, ActivityEntry::where('action', 'export.downloaded')->count());
    }

    public function test_pdf_has_a_lower_row_limit_than_csv(): void
    {
        $this->bigDataset(ExportWriter::MAX_ROWS_PDF + 1);

        $this->actingAs($this->manager)->download(['dataset' => 'big', 'target' => 'stores', 'format' => 'pdf'])->assertSessionHasErrors('export');
        $this->actingAs($this->manager)->download(['dataset' => 'big', 'target' => 'stores', 'format' => 'csv'])->assertOk();
    }

    public function test_the_page_shows_the_limit_message_and_does_not_start_the_download(): void
    {
        $this->bigDataset(ExportWriter::MAX_ROWS + 1);
        $this->actingAs($this->manager);

        Livewire::test('pages::exports.index')
            ->set('dataset', 'big')
            ->call('download')
            ->assertHasErrors('export')
            ->assertNoRedirect()
            ->assertSee('20 001 rows');
    }

    public function test_ics_works_for_one_employee_and_is_refused_otherwise(): void
    {
        $shift = $this->shift($this->employee, '2026-11-10', ['notes' => 'Hello']);
        $this->shift($this->employeeOf($this->store), '2026-11-11');
        $this->actingAs($this->manager);

        $ok = $this->download(['target' => 'stores', 'format' => 'ics', 'users' => [$this->employee->id]]);
        $ok->assertOk();
        $this->assertStringStartsWith('text/calendar', $ok->headers->get('Content-Type'));
        $body = $ok->streamedContent();
        $this->assertStringContainsString("UID:{$shift->ical_uid}\r\n", $body);
        $this->assertSame(1, substr_count($body, 'BEGIN:VEVENT'));

        $this->download(['target' => 'stores', 'format' => 'ics'])->assertSessionHasErrors('format');
        $this->download(['dataset' => 'stores', 'target' => 'stores', 'format' => 'ics'])->assertSessionHasErrors('format');
    }

    public function test_an_employee_exports_their_own_shifts_only(): void
    {
        $this->shift($this->employee, '2026-11-10');
        $this->shift($this->employeeOf($this->store, ['name' => 'Colleague']), '2026-11-11');

        $body = $this->actingAs($this->employee)->download(['target' => 'me'])->streamedContent();

        $this->assertStringContainsString('Anna', $body);
        $this->assertStringNotContainsString('Colleague', $body);
    }

    public function test_an_employee_cannot_export_the_stores_or_the_payroll(): void
    {
        $this->actingAs($this->employee);

        $this->download(['target' => 'stores'])->assertForbidden();
        $this->download(['dataset' => 'payroll', 'target' => 'me'])->assertForbidden();
        $this->download(['dataset' => 'stores', 'target' => 'me'])->assertForbidden();
    }

    public function test_a_manager_cannot_export_another_store(): void
    {
        $other = Store::factory()->create();
        $this->shift($this->employeeOf($other, ['name' => 'Elsewhere']), '2026-11-10', ['store_id' => $other->id]);
        $this->actingAs($this->manager);

        $this->download(['target' => 'stores', 'stores' => [$other->id]])->assertForbidden();
        $body = $this->download(['target' => 'stores'])->streamedContent();
        $this->assertStringNotContainsString('Elsewhere', $body);
    }

    public function test_an_employee_id_slipped_in_the_request_never_gives_access_to_another_store(): void
    {
        $stranger = $this->employeeOf(Store::factory()->create(), ['name' => 'Elsewhere']);
        $this->shift($stranger, '2026-11-10', ['store_id' => $stranger->stores()->first()->id]);
        $this->actingAs($this->manager);

        $body = $this->download(['target' => 'stores', 'users' => [$stranger->id]])->streamedContent();

        $this->assertStringNotContainsString('Elsewhere', $body);
    }

    public function test_an_owner_shifts_are_hidden_from_a_manager(): void
    {
        $owner = $this->owner();
        $owner->forceFill(['name' => 'The Owner'])->save();
        $this->store->members()->attach($owner);
        $this->shift($owner, '2026-11-10');

        $this->assertStringNotContainsString('The Owner', $this->actingAs($this->manager)->download(['target' => 'stores'])->streamedContent());
        $this->assertStringContainsString('The Owner', $this->actingAs($owner)->download(['target' => 'stores'])->streamedContent());
    }

    public function test_an_invalid_period_or_unknown_dataset_is_rejected(): void
    {
        $this->actingAs($this->manager);

        $this->download(['target' => 'stores', 'from' => '2026-11-30', 'to' => '2026-11-01'])->assertSessionHasErrors('from');
        $this->download(['dataset' => 'unknown'])->assertNotFound();
        $this->download(['target' => 'stores', 'format' => 'docx'])->assertSessionHasErrors('format');
    }

    public function test_guests_are_sent_to_the_login(): void
    {
        $this->download([])->assertRedirect(route('login'));
    }

    public function test_every_download_is_logged_without_the_content(): void
    {
        $this->shift($this->employee, '2026-11-10', ['notes' => 'secret note']);

        $this->actingAs($this->manager)->download(['target' => 'stores', 'format' => 'xlsx'])->streamedContent();

        $entry = ActivityEntry::where('action', 'export.downloaded')->sole();
        $this->assertSame($this->manager->id, $entry->actor_id);
        $this->assertSame($this->store->id, $entry->store_id);
        $this->assertSame('shifts', $entry->properties['dataset']);
        $this->assertSame('xlsx', $entry->properties['format']);
        $this->assertSame(1, $entry->properties['count']);
        $this->assertSame(['Alpha'], $entry->properties['stores']);
        $this->assertSame('2026-11-01', $entry->properties['from']);
        $this->assertStringNotContainsString('secret note', json_encode($entry->properties));
    }

    public function test_the_exports_page_offers_what_the_user_may_export(): void
    {
        $this->actingAs($this->employee);
        Livewire::test('pages::exports.index')
            ->assertSet('target', 'me')
            ->assertSee('Shifts')
            ->assertDontSee('Hours and costs')
            ->set('dataset', 'employees')
            ->call('download')
            ->assertRedirect(route('exports.download', ['dataset' => 'employees', 'format' => 'csv', 'target' => 'me']));

        $this->actingAs($this->manager);
        Livewire::test('pages::exports.index')
            ->assertSet('target', 'stores')
            ->assertSee('Hours and costs')
            ->assertSee('Anna')
            ->set('dataset', 'payroll')
            ->set('storeIds', [(string) $this->store->id])
            ->set('userIds', [(string) $this->employee->id])
            ->set('from', '2026-11-01')
            ->set('to', '2026-11-30')
            ->set('format', 'xlsx')
            ->call('download')
            ->assertRedirect(route('exports.download', [
                'dataset' => 'payroll', 'format' => 'xlsx', 'target' => 'stores', 'stores' => [$this->store->id],
                'users' => [$this->employee->id], 'from' => '2026-11-01', 'to' => '2026-11-30',
            ]));
    }

    public function test_the_exports_page_validates_the_period(): void
    {
        $this->actingAs($this->manager);

        Livewire::test('pages::exports.index')
            ->set('from', '2026-11-30')
            ->set('to', '2026-11-01')
            ->call('download')
            ->assertHasErrors('from')
            ->assertNoRedirect();
    }

    public function test_the_exports_page_is_open_to_everyone_and_listed_in_the_menu(): void
    {
        $this->actingAs($this->employee)->get(route('exports.index'))->assertOk()->assertSee(route('exports.index'));
        $this->actingAs(User::factory()->create())->get(route('exports.index'))->assertOk()->assertSee('Nothing to export yet.');
        auth()->logout();
        $this->get(route('exports.index'))->assertRedirect(route('login'));
    }

    private function readSheet(string $content): Worksheet
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $content);

        try {
            return IOFactory::load($path)->getActiveSheet();
        } finally {
            unlink($path);
        }
    }

    /**
     * Jeu de données d'un bundle fictif qui annonce `$rows` lignes sans les créer.
     */
    private function bigDataset(int $rows): void
    {
        $dataset = new class($rows) extends AbstractExportDataset
        {
            public function __construct(private readonly int $rows) {}

            public function key(): string
            {
                return 'big';
            }

            public function label(): string
            {
                return 'Big data set';
            }

            public function permission(): string
            {
                return 'shifts.view';
            }

            public function perEmployee(): bool
            {
                return false;
            }

            public function columns(): array
            {
                return [new ExportColumn('n', 'Number', ExportColumn::INTEGER)];
            }

            public function count(ExportScope $scope): int
            {
                return $this->rows;
            }

            public function rows(ExportScope $scope): iterable
            {
                for ($i = 1; $i <= $this->rows; $i++) {
                    yield ['n' => $i];
                }
            }

            protected function query(ExportScope $scope): Builder
            {
                throw new \LogicException('Not used.');
            }

            protected function row(mixed $record): array
            {
                return [];
            }
        };

        app(ExportDatasetRegistry::class)->register($dataset);
    }
}
