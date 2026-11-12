<?php

namespace Kintai\Bundles\Timeclock\Tests;

use Carbon\Carbon;
use Kintai\Bundles\Timeclock\OvertimeCalculator;
use Kintai\Bundles\Timeclock\OvertimeReport;
use Kintai\Bundles\Timeclock\OvertimeRules;
use PHPUnit\Framework\TestCase;

/**
 * Calcul pur des heures supplémentaires : seuils journalier et hebdomadaire, limites exactes, plusieurs pointages,
 * minuit, pointage en cours, seuils désactivés, début de semaine.
 */
class OvertimeCalculatorTest extends TestCase
{
    private const TZ = 'Asia/Tokyo';

    private OvertimeCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new OvertimeCalculator;
    }

    /** Lundi 2 novembre 2026, minuit à Tokyo. */
    private function monday(): Carbon
    {
        return Carbon::parse('2026-11-02 00:00:00', self::TZ);
    }

    private function at(string $local): Carbon
    {
        return Carbon::parse($local, self::TZ)->utc();
    }

    /**
     * @param  array<int|string, array{0: string, 1: string|null}>  $local
     * @return array<int|string, array{0: Carbon, 1: Carbon|null}>
     */
    private function intervals(array $local): array
    {
        return array_map(fn (array $pair): array => [$this->at($pair[0]), $pair[1] === null ? null : $this->at($pair[1])], $local);
    }

    private function rules(bool $enabled = true, int $daily = 8, int $weekly = 40, int $weekStart = 1): OvertimeRules
    {
        return new OvertimeRules($enabled, $daily * 60, $weekly * 60, $weekStart);
    }

    /**
     * @param  array<int|string, array{0: string, 1: string|null}>  $local
     */
    private function week(array $local, ?OvertimeRules $rules = null, string $now = '2026-11-20 12:00:00'): OvertimeReport
    {
        return $this->calculator->week($this->intervals($local), $this->monday(), $rules ?? $this->rules(), $this->at($now), self::TZ);
    }

    public function test_exactly_the_daily_threshold_is_not_overtime(): void
    {
        $report = $this->week([1 => ['2026-11-02 09:00:00', '2026-11-02 17:00:00']]);

        $this->assertSame(480, $report->workedMinutes);
        $this->assertSame(0, $report->overtimeMinutes());
        $this->assertSame(0, $report->forEntry(1));
    }

    public function test_one_minute_over_the_daily_threshold_is_overtime(): void
    {
        $report = $this->week([1 => ['2026-11-02 09:00:00', '2026-11-02 17:01:00']]);

        $this->assertSame(1, $report->dailyOvertimeMinutes);
        $this->assertSame(1, $report->forEntry(1));
        $this->assertSame(['2026-11-02' => 1], $report->dailyOvertime);
    }

    public function test_several_entries_of_the_same_day_add_up_and_the_last_one_carries_the_overtime(): void
    {
        $report = $this->week([
            1 => ['2026-11-02 08:00:00', '2026-11-02 12:00:00'],
            2 => ['2026-11-02 13:00:00', '2026-11-02 17:30:00'],
        ]);

        $this->assertSame(510, $report->days['2026-11-02']);
        $this->assertSame(0, $report->forEntry(1));
        $this->assertSame(30, $report->forEntry(2));
        $this->assertSame(30, $report->overtimeMinutes());
    }

    public function test_the_weekly_threshold_counts_what_the_daily_one_did_not(): void
    {
        // 5 jours de 9 h : 5 h supplémentaires par jour, 40 h hors heures sup. journalières : rien de plus par semaine.
        $entries = [];
        foreach (['02', '03', '04', '05', '06'] as $day) {
            $entries[(int) $day] = ["2026-11-{$day} 09:00:00", "2026-11-{$day} 18:00:00"];
        }

        $report = $this->week($entries);

        $this->assertSame(2700, $report->workedMinutes);
        $this->assertSame(300, $report->dailyOvertimeMinutes);
        $this->assertSame(0, $report->weeklyOvertimeMinutes);
        $this->assertSame(2400, $report->netMinutes);
    }

    public function test_weekly_overtime_appears_beyond_the_threshold_without_double_counting(): void
    {
        // 6 jours de 7 h = 42 h, aucune heure sup. journalière : 2 h supplémentaires, portées par le dernier jour.
        $entries = [];
        foreach (['02', '03', '04', '05', '06', '07'] as $day) {
            $entries[(int) $day] = ["2026-11-{$day} 09:00:00", "2026-11-{$day} 16:00:00"];
        }

        $report = $this->week($entries);

        $this->assertSame(0, $report->dailyOvertimeMinutes);
        $this->assertSame(120, $report->weeklyOvertimeMinutes);
        $this->assertSame(120, $report->forEntry(7));
        $this->assertSame(0, $report->forEntry(6));
    }

    public function test_exactly_the_weekly_threshold_is_not_overtime(): void
    {
        $entries = [];
        foreach (['02', '03', '04', '05', '06'] as $day) {
            $entries[(int) $day] = ["2026-11-{$day} 09:00:00", "2026-11-{$day} 17:00:00"];
        }

        $report = $this->week($entries);

        $this->assertSame(2400, $report->workedMinutes);
        $this->assertSame(0, $report->overtimeMinutes());
    }

    public function test_a_disabled_feature_or_zero_thresholds_produce_no_overtime(): void
    {
        $long = [1 => ['2026-11-02 06:00:00', '2026-11-02 22:00:00']];

        $this->assertSame(0, $this->week($long, $this->rules(enabled: false))->overtimeMinutes());
        $this->assertSame(960, $this->week($long, $this->rules(enabled: false))->workedMinutes);
        $this->assertSame(0, $this->week($long, $this->rules(daily: 0, weekly: 0))->overtimeMinutes());
        $this->assertSame(480, $this->week($long, $this->rules(daily: 8, weekly: 0))->overtimeMinutes());
        $this->assertSame(0, $this->week($long, $this->rules(daily: 0, weekly: 20))->overtimeMinutes());
        $this->assertSame(60, $this->week($long, $this->rules(daily: 0, weekly: 15))->overtimeMinutes());
    }

    public function test_the_thresholds_are_parameters_not_constants(): void
    {
        $day = [1 => ['2026-11-02 09:00:00', '2026-11-02 17:00:00']];

        $this->assertSame(60, $this->week($day, $this->rules(daily: 7))->overtimeMinutes());
        $this->assertSame(240, $this->week($day, $this->rules(daily: 4))->overtimeMinutes());
    }

    public function test_a_shift_across_midnight_is_split_between_the_two_days(): void
    {
        $report = $this->week([1 => ['2026-11-02 20:00:00', '2026-11-03 07:00:00']]);

        $this->assertSame(['2026-11-02' => 240, '2026-11-03' => 420], $report->days);
        $this->assertSame(0, $report->overtimeMinutes());

        $long = $this->week([1 => ['2026-11-02 14:00:00', '2026-11-03 06:00:00']]);
        $this->assertSame(['2026-11-02' => 600, '2026-11-03' => 360], $long->days);
        $this->assertSame(120, $long->dailyOvertimeMinutes);
    }

    public function test_the_part_of_a_shift_in_the_next_week_is_not_counted_in_this_one(): void
    {
        $report = $this->week([1 => ['2026-11-08 20:00:00', '2026-11-09 03:00:00']]);

        $this->assertSame(240, $report->workedMinutes);
    }

    public function test_a_shift_starting_in_the_previous_week_counts_from_the_first_day(): void
    {
        $report = $this->week([1 => ['2026-11-01 22:00:00', '2026-11-02 02:00:00']]);

        $this->assertSame(120, $report->workedMinutes);
    }

    public function test_a_running_entry_counts_until_now(): void
    {
        $report = $this->week([1 => ['2026-11-02 09:00:00', null]], null, '2026-11-02 18:30:00');

        $this->assertSame(570, $report->workedMinutes);
        $this->assertSame(90, $report->forEntry(1));
    }

    public function test_the_week_start_is_configurable(): void
    {
        $rules = $this->rules(weekStart: 0);

        $sunday = $this->calculator->weekStart(Carbon::parse('2026-11-04 12:00:00', self::TZ), $rules, self::TZ);
        $this->assertSame('2026-11-01', $sunday->toDateString());

        $monday = $this->calculator->weekStart(Carbon::parse('2026-11-08 23:00:00', self::TZ), $this->rules(), self::TZ);
        $this->assertSame('2026-11-02', $monday->toDateString());

        $saturday = $this->calculator->weekStart(Carbon::parse('2026-11-06 10:00:00', self::TZ), $this->rules(weekStart: 6), self::TZ);
        $this->assertSame('2026-10-31', $saturday->toDateString());
    }

    public function test_days_follow_the_local_timezone_not_utc(): void
    {
        // 08:00 à Tokyo = 23:00 UTC la veille : le jour local est bien le 2.
        $report = $this->week([1 => ['2026-11-02 08:00:00', '2026-11-02 18:00:00']]);

        $this->assertSame(['2026-11-02' => 600], $report->days);
        $this->assertSame(120, $report->forEntry(1));
    }
}
