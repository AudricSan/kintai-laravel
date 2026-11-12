<?php

namespace Kintai\Bundles\PlanningImport\Tests;

use Kintai\Bundles\PlanningImport\Detection\BusinessQualifier;
use Kintai\Bundles\PlanningImport\Detection\ColumnTimeCalculator;
use Kintai\Bundles\PlanningImport\Detection\ConfidenceScorer;
use Kintai\Bundles\PlanningImport\Detection\GridSettings;
use Kintai\Bundles\PlanningImport\Detection\QualifiedSegment;
use Kintai\Bundles\PlanningImport\Detection\Segment;
use Kintai\Bundles\PlanningImport\Detection\ShiftSheetParser;
use Kintai\Bundles\PlanningImport\Detection\StrictDeduplicator;
use Kintai\Bundles\PlanningImport\ImportException;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/PlanningSheet.php';

/**
 * Détection en trois phases sur des fichiers synthétiques : couleurs, bordures et grille horaire (phase 1), qualification
 * avec score de confiance (phase 2), déduplication stricte (phase 3).
 */
class DetectionTest extends TestCase
{
    private PlanningSheet $file;

    protected function setUp(): void
    {
        $this->file = new PlanningSheet;
    }

    protected function tearDown(): void
    {
        $this->file->cleanup();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parse(?GridSettings $grid = null, string $name = 'planning.xlsx'): array
    {
        return (new ShiftSheetParser($grid ?? new GridSettings))->parse($this->file->save(), $name);
    }

    private function segment(?string $color, ?string $name, int $minutes, bool $top = false, bool $bottom = false): Segment
    {
        return new Segment(2, '2026-11-03', 4, 11, '06:00', '10:00', $minutes, intdiv($minutes, 30), $color, $top, $bottom, $name);
    }

    public function test_phase_1_reads_a_colored_segment_with_the_times_of_its_columns(): void
    {
        // Colonnes 4 à 11 : 06:00 à 10:00 (la colonne 12 est 10:00).
        $this->file->date(1, '2026-11-03')->colored(1, 2, 4, 11, 'FFAA00', 'Tanaka');

        $this->assertSame([[
            'date' => '2026-11-03', 'staff_name' => 'Tanaka', 'start_time' => '06:00', 'end_time' => '10:00', 'hours' => 4.0, 'duplicate_count' => 0,
        ]], $this->parse());
    }

    public function test_phase_1_reads_every_block_and_every_shift_row(): void
    {
        $this->file->date(1, '2026-11-03')->colored(1, 1, 4, 9, 'FFAA00', 'Tanaka')->colored(1, 3, 10, 15, '00AAFF', 'Sato');
        $this->file->date(2, '2026-11-04')->colored(2, 1, 4, 9, 'FFAA00', 'Tanaka');

        $shifts = $this->parse();

        $this->assertSame(
            ['2026-11-03 Tanaka 06:00-09:00', '2026-11-03 Sato 09:00-12:00', '2026-11-04 Tanaka 06:00-09:00'],
            array_map(fn (array $s): string => "{$s['date']} {$s['staff_name']} {$s['start_time']}-{$s['end_time']}", $shifts),
        );
    }

    public function test_the_date_can_be_an_excel_serial_number(): void
    {
        // 46329 = 2026-11-03.
        $this->file->date(1, 46329)->colored(1, 1, 4, 9, 'FFAA00', 'Tanaka');

        $this->assertSame('2026-11-03', $this->parse()[0]['date']);
    }

    public function test_a_block_without_date_is_ignored(): void
    {
        $this->file->colored(1, 1, 4, 9, 'FFAA00', 'Tanaka');

        $this->assertSame([], $this->parse());
    }

    public function test_the_file_name_gives_the_year_and_month_and_the_sheet_the_day(): void
    {
        $this->file->date(1, '2026-11-03')->colored(1, 1, 4, 9, 'FFAA00', 'Tanaka');

        $this->assertSame('2027-02-03', $this->parse(null, '2027年2月 planning.xlsx')[0]['date']);
    }

    public function test_the_color_gives_the_name_to_a_segment_without_text(): void
    {
        $this->file->date(1, '2026-11-03')->colored(1, 1, 4, 9, 'FFAA00', 'Tanaka');
        $this->file->date(2, '2026-11-04')->colored(2, 1, 4, 9, 'FFAA00', null);

        $this->assertSame(['Tanaka', 'Tanaka'], array_column($this->parse(), 'staff_name'));
    }

    public function test_numeric_cells_are_hours_of_the_timeline_not_names(): void
    {
        $this->file->date(1, '2026-11-03')->colored(1, 1, 4, 9, 'FFAA00', '6');

        $this->assertSame([], $this->parse());
    }

    public function test_the_name_can_appear_after_the_first_cell_of_the_segment(): void
    {
        $this->file->date(1, '2026-11-03')->colored(1, 1, 4, 9, 'FFAA00', null);
        $this->file->sheet->setCellValue('E'.(PlanningSheet::block(1) + 1), "Tanaka \u{3000}");

        $this->assertSame('Tanaka', $this->parse()[0]['staff_name']);
    }

    public function test_phase_1_splits_segments_when_the_color_changes(): void
    {
        $this->file->date(1, '2026-11-03')->colored(1, 1, 4, 7, 'FFAA00', 'Tanaka')->colored(1, 1, 8, 11, '00AAFF', 'Sato');

        $this->assertSame(['Tanaka 06:00-08:00', 'Sato 08:00-10:00'], array_map(fn (array $s): string => "{$s['staff_name']} {$s['start_time']}-{$s['end_time']}", $this->parse()));
    }

    public function test_times_wrap_after_midnight(): void
    {
        // Colonnes 36 à 41 : 22:00 à 01:00 le lendemain.
        $this->file->date(1, '2026-11-03')->colored(1, 1, 36, 41, 'FFAA00', 'Tanaka');

        $shift = $this->parse()[0];
        $this->assertSame(['22:00', '01:00', 3.0], [$shift['start_time'], $shift['end_time'], $shift['hours']]);
    }

    public function test_the_last_column_of_the_grid_ends_on_its_own_time(): void
    {
        // Colonne 52 : un segment qui y finit se termine sur l'heure de cette colonne (06:00), comme à l'ancienne version.
        $this->file->date(1, '2026-11-03')->colored(1, 1, 49, 52, 'FFAA00', 'Tanaka');

        $shift = $this->parse()[0];
        $this->assertSame(['04:30', '06:00', 2.0], [$shift['start_time'], $shift['end_time'], $shift['hours']]);
    }

    public function test_a_name_ending_with_a_character_whose_last_byte_is_0x80_is_kept_whole(): void
    {
        $this->file->date(1, '2026-11-03')->colored(1, 1, 4, 9, 'FFAA00', "たなむ\u{3000}");

        $this->assertSame('たなむ', $this->parse()[0]['staff_name']);
    }

    public function test_a_white_segment_needs_name_duration_and_both_borders_to_be_a_shift(): void
    {
        $this->file->date(1, '2026-11-03');
        $this->file->bordered(1, 1, 4, 11, 'Manager');                   // 4 h, deux bordures : shift
        $this->file->bordered(1, 2, 4, 9, 'Short');                      // 3 h : absence
        $this->file->bordered(1, 3, 4, 11, 'Noborder', bottom: false);   // une seule bordure : absence
        $this->file->bordered(1, 4, 4, 11, null);                        // sans nom : absence
        $this->file->bordered(1, 5, 4, 11, 'X');                         // nom d'un caractère : absence

        $shifts = $this->parse();

        $this->assertCount(1, $shifts);
        $this->assertSame(['Manager', '06:00', '10:00'], [$shifts[0]['staff_name'], $shifts[0]['start_time'], $shifts[0]['end_time']]);
    }

    public function test_a_colored_segment_without_a_valid_name_is_an_absence(): void
    {
        $this->file->date(1, '2026-11-03')->colored(1, 1, 4, 11, 'FFAA00', 'Y')->colored(1, 2, 4, 11, '00AAFF', '12');

        $this->assertSame([], $this->parse());
    }

    public function test_a_black_or_white_fill_is_a_white_background(): void
    {
        $this->file->date(1, '2026-11-03')->colored(1, 1, 4, 11, '000000', 'Tanaka');

        // Fond noir : fond « blanc », donc sans bordures c'est une absence.
        $this->assertSame([], $this->parse());
    }

    public function test_noise_between_two_segments_of_the_same_color_is_merged(): void
    {
        $grid = new GridSettings(minutesPerCol: 15);
        // 6 colonnes, 1 colonne d'une autre couleur (15 min : du bruit), 6 colonnes : un seul shift de 13 colonnes.
        $this->file->date(1, '2026-11-03')->colored(1, 1, 4, 9, 'FFAA00', 'Tanaka')->colored(1, 1, 10, 10, '00AAFF', null)->colored(1, 1, 11, 16, 'FFAA00', null);

        $shifts = $this->parse($grid);

        $this->assertCount(1, $shifts);
        $this->assertSame(['Tanaka', '06:00', '09:15', 3.3], [$shifts[0]['staff_name'], $shifts[0]['start_time'], $shifts[0]['end_time'], $shifts[0]['hours']]);
    }

    public function test_isolated_noise_is_dropped(): void
    {
        $grid = new GridSettings(minutesPerCol: 15);
        $this->file->date(1, '2026-11-03')->colored(1, 1, 4, 9, 'FFAA00', 'Tanaka')->colored(1, 1, 10, 10, '00AAFF', 'Sato');

        $this->assertSame(['Tanaka'], array_column($this->parse($grid), 'staff_name'));
    }

    public function test_phase_3_keeps_the_first_of_identical_shifts_and_counts_the_others(): void
    {
        $this->file->date(1, '2026-11-03')->colored(1, 1, 4, 9, 'FFAA00', 'Tanaka')->colored(1, 4, 4, 9, 'FFAA00', 'Tanaka')->colored(1, 5, 4, 9, 'FFAA00', 'Tanaka');

        $shifts = $this->parse();

        $this->assertCount(1, $shifts);
        $this->assertSame(2, $shifts[0]['duplicate_count']);
    }

    public function test_only_the_sheets_matching_the_pattern_are_read(): void
    {
        $this->file->date(1, '2026-11-03')->colored(1, 1, 4, 9, 'FFAA00', 'Tanaka');
        $notes = $this->file->addSheet('Notes');
        $this->file->date(1, '2026-11-03', $notes)->colored(1, 1, 4, 9, '00AAFF', 'Sato', $notes);

        $this->assertSame(['Tanaka'], array_column($this->parse(), 'staff_name'));
    }

    public function test_every_sheet_is_read_when_none_matches_the_pattern(): void
    {
        $this->file->sheet->setTitle('Alpha');
        $this->file->date(1, '2026-11-03')->colored(1, 1, 4, 9, 'FFAA00', 'Tanaka');

        $this->assertSame(['Tanaka'], array_column($this->parse(), 'staff_name'));
        $this->assertSame(['Tanaka'], array_column($this->parse(new GridSettings(sheetPattern: '(')), 'staff_name'));
    }

    public function test_the_grid_comes_from_the_settings(): void
    {
        // Grille d'un autre magasin : colonnes 2 à 20, 08:00, 60 minutes par colonne, blocs de 5 lignes, 3 lignes de shifts.
        $this->file->sheet->setCellValue('A3', '2026-11-03');
        $this->file->sheet->setCellValue('B2', 'Tanaka');
        foreach (['B2', 'C2', 'D2', 'E2'] as $cell) {
            $this->file->sheet->getStyle($cell)->getFill()->setFillType('solid')->getStartColor()->setRGB('FFAA00');
        }

        $shifts = $this->parse(new GridSettings(colStart: 2, colEnd: 20, baseHour: 8, minutesPerCol: 60, blockSize: 5, shiftRows: 3));

        $this->assertSame(['Tanaka', '08:00', '12:00', 4.0], [$shifts[0]['staff_name'], $shifts[0]['start_time'], $shifts[0]['end_time'], $shifts[0]['hours']]);
    }

    public function test_out_of_range_settings_fall_back_to_safe_values(): void
    {
        $grid = new GridSettings(colStart: 0, colEnd: -3, baseHour: 40, minutesPerCol: 0, blockSize: 0, shiftRows: 0);

        $this->assertSame([1, 1, 6, 30, 2, 1], [$grid->colStart, $grid->colEnd, $grid->baseHour, $grid->minutesPerCol, $grid->blockSize, $grid->shiftRows]);
    }

    public function test_the_automatic_break_applies_from_the_configured_duration(): void
    {
        $this->assertSame(0, (new GridSettings)->pauseFor(600));
        $grid = new GridSettings(autoPauseAfterMinutes: 360, autoPauseMinutes: 45);

        $this->assertSame([0, 45, 45], [$grid->pauseFor(359), $grid->pauseFor(360), $grid->pauseFor(600)]);
    }

    public function test_the_time_calculator_rejects_inverted_ranges(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ColumnTimeCalculator(new GridSettings))->range(10, 5);
    }

    public function test_a_missing_or_unreadable_file_is_refused(): void
    {
        $parser = new ShiftSheetParser;

        $this->expectException(ImportException::class);
        $parser->parse(sys_get_temp_dir().'/does-not-exist.xlsx');
    }

    public function test_a_file_that_is_not_a_spreadsheet_is_refused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bad').'.xlsx';
        file_put_contents($path, 'PK'.chr(3).chr(4).' corrupted archive');

        try {
            $this->expectException(ImportException::class);
            (new ShiftSheetParser)->parse($path);
        } finally {
            @unlink($path);
        }
    }

    public function test_phase_2_scores_a_colored_segment_with_a_name(): void
    {
        $qualified = (new BusinessQualifier)->qualifyOne($this->segment('FFFFAA00', 'Tanaka', 480, true, true));

        $this->assertSame([QualifiedSegment::SHIFT, 100], [$qualified->type, $qualified->confidence]);
    }

    public function test_phase_2_score_depends_on_name_borders_and_duration(): void
    {
        $scorer = new ConfidenceScorer;

        $this->assertSame(50, $scorer->score($this->segment('FFFFAA00', 'Tanaka', 60), QualifiedSegment::SHIFT));                  // 40 / 80
        $this->assertSame(62, $scorer->score($this->segment('FFFFAA00', 'Tanaka', 240), QualifiedSegment::SHIFT));                 // 50 / 80
        $this->assertSame(87, $scorer->score($this->segment('FFFFAA00', 'Tanaka', 240, true, true), QualifiedSegment::SHIFT));     // 70 / 80
        $this->assertSame(0, $scorer->score($this->segment('FFFFAA00', null, 60), QualifiedSegment::SHIFT));
    }

    public function test_phase_2_scores_an_absence_in_reverse(): void
    {
        $scorer = new ConfidenceScorer;

        // Rien ne ressemble à un shift : l'absence est sûre à 100 %.
        $this->assertSame(100, $scorer->score($this->segment(null, null, 60), QualifiedSegment::ABSENCE));
        // Un nom valide et 8 h : peu probable que ce soit une absence.
        $this->assertSame(0, $scorer->score($this->segment(null, 'Tanaka', 480, true, true), QualifiedSegment::ABSENCE));
    }

    public function test_phase_2_qualifies_white_and_colored_segments(): void
    {
        $qualifier = new BusinessQualifier;

        $this->assertTrue($qualifier->qualifyOne($this->segment('FFFFFFFF', 'Tanaka', 240, true, true))->isShift());
        $this->assertFalse($qualifier->qualifyOne($this->segment(null, 'Tanaka', 240, true, false))->isShift());
        $this->assertFalse($qualifier->qualifyOne($this->segment(null, 'Tanaka', 210, true, true))->isShift());
        $this->assertTrue($qualifier->qualifyOne($this->segment('FFFFAA00', 'Tanaka', 30))->isShift());
        $this->assertFalse($qualifier->qualifyOne($this->segment('FFFFAA00', ' 7 ', 480))->isShift());
        $this->assertFalse($qualifier->qualifyOne($this->segment('FFFFAA00', null, 480))->isShift());
    }

    public function test_phase_3_drops_absences_and_deduplicates_on_name_date_and_times(): void
    {
        $qualifier = new BusinessQualifier;
        $segments = [
            $this->segment('FFFFAA00', 'Tanaka', 240),
            $this->segment('FFFFAA00', ' Tanaka ', 240),
            $this->segment('FFFFAA00', 'Sato', 240),
            $this->segment('FFFFAA00', null, 240),
        ];

        $shifts = (new StrictDeduplicator)->deduplicate($qualifier->qualify($segments));

        $this->assertSame(['Tanaka|2026-11-03|06:00|10:00', 'Sato|2026-11-03|06:00|10:00'], array_map(fn ($s): string => $s->key, $shifts));
        $this->assertSame([1, 0], array_map(fn ($s): int => $s->duplicates, $shifts));
    }
}
