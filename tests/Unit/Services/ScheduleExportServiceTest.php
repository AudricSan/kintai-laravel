<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Services;

use kintai\Core\Services\ScheduleExportService;
use PHPUnit\Framework\TestCase;

/** Export de l'horaire d'un employé : période bornée, shifts à plat, CSV sûr pour un tableur. */
final class ScheduleExportServiceTest extends TestCase
{
    private ScheduleExportService $service;

    protected function setUp(): void
    {
        $this->service = new ScheduleExportService();
    }

    public function testPeriodDefaultsToTheNext90Days(): void
    {
        $this->assertSame(['from' => '2026-10-23', 'to' => '2027-01-21'], $this->service->period(null, null, '2026-10-23'));
    }

    public function testInvalidDatesFallBackToTheDefaults(): void
    {
        $this->assertSame(['from' => '2026-10-23', 'to' => '2027-01-21'], $this->service->period('pas une date', '2026-02-30', '2026-10-23'));
    }

    public function testReversedBoundsAreSwapped(): void
    {
        $this->assertSame(['from' => '2026-11-01', 'to' => '2026-12-01'], $this->service->period('2026-12-01', '2026-11-01', '2026-10-23'));
    }

    public function testThePeriodIsCappedToOneYear(): void
    {
        $period = $this->service->period('2026-01-01', '2035-01-01', '2026-10-23');

        $this->assertSame('2026-01-01', $period['from']);
        $this->assertSame('2027-01-02', $period['to']);
    }

    public function testSelectKeepsThePeriodInChronologicalOrderWithoutDeletedShifts(): void
    {
        $shifts = [
            ['id' => 3, 'shift_date' => '2026-11-03', 'start_time' => '09:00'],
            ['id' => 1, 'shift_date' => '2026-11-02', 'start_time' => '14:00'],
            ['id' => 2, 'shift_date' => '2026-11-02', 'start_time' => '08:00'],
            ['id' => 4, 'shift_date' => '2026-11-02', 'start_time' => '10:00', 'deleted_at' => '2026-10-30 10:00:00'],
            ['id' => 5, 'shift_date' => '2026-10-01', 'start_time' => '09:00'],
            ['id' => 6, 'shift_date' => '2026-12-31', 'start_time' => '09:00'],
        ];

        $kept = $this->service->select($shifts, '2026-11-01', '2026-11-30');

        $this->assertSame([2, 1, 3], array_column($kept, 'id'));
    }

    public function testRowsComputeTheNetDurationAndResolveNames(): void
    {
        $rows = $this->service->rows(
            [['shift_date' => '2026-11-02', 'start_time' => '09:00:00', 'end_time' => '17:30:00', 'pause_minutes' => 60, 'shift_type_id' => 7, 'notes' => 'Caisse']],
            ['name' => 'Shibuya'],
            [7 => ['name' => 'Matin']],
        );

        $this->assertSame([[
            'date'          => '2026-11-02',
            'start_time'    => '09:00',
            'end_time'      => '17:30',
            'ends_next_day' => false,
            'break_minutes' => 60,
            'net_minutes'   => 450,
            'shift_type'    => 'Matin',
            'store'         => 'Shibuya',
            'notes'         => 'Caisse',
        ]], $rows);
    }

    public function testAShiftOverMidnightCountsTheNextDay(): void
    {
        $rows = $this->service->rows(
            [['shift_date' => '2026-11-02', 'start_time' => '22:00', 'end_time' => '06:00', 'cross_midnight' => 1, 'pause_minutes' => 0]],
            ['name' => 'Shibuya'],
            [],
        );

        $this->assertTrue($rows[0]['ends_next_day']);
        $this->assertSame(480, $rows[0]['net_minutes']);
        $this->assertSame('', $rows[0]['shift_type']);
    }

    public function testJsonCarriesTheStorePeriodAndCount(): void
    {
        $rows = $this->service->rows([['shift_date' => '2026-11-02', 'start_time' => '09:00', 'end_time' => '10:00']], ['name' => 'Shibuya'], []);
        $json = $this->service->json($rows, ['id' => 4, 'name' => 'Shibuya', 'timezone' => 'Asia/Tokyo'], '2026-11-01', '2026-11-30');

        $this->assertSame(['id' => 4, 'name' => 'Shibuya', 'timezone' => 'Asia/Tokyo'], $json['store']);
        $this->assertSame(1, $json['count']);
        $this->assertSame('2026-11-30', $json['to']);
        $this->assertCount(1, $json['shifts']);
    }

    public function testCsvStartsWithABomAndTheHeader(): void
    {
        $csv = $this->service->csv([]);

        $this->assertStringStartsWith("\xEF\xBB\xBFdate;start_time;end_time;ends_next_day;break_minutes;net_minutes;shift_type;store;notes\r\n", $csv);
    }

    public function testCsvQuotesSeparatorsAndNeutralisesFormulas(): void
    {
        $rows = $this->service->rows(
            [['shift_date' => '2026-11-02', 'start_time' => '09:00', 'end_time' => '10:00', 'notes' => "=HYPERLINK(\"http://x\")\nligne 2; fin"]],
            ['name' => 'Shibuya'],
            [],
        );
        $line = explode("\r\n", $this->service->csv($rows))[1];

        // Une apostrophe devant « = » (pas de formule), séparateur et guillemets protégés, saut de ligne aplati.
        $this->assertStringEndsWith('"\'=HYPERLINK(""http://x"") ligne 2; fin"', $line);
        $this->assertStringStartsWith('2026-11-02;09:00;10:00;0;0;60;;Shibuya;', $line);
    }
}
