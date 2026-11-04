<?php

namespace Tests\Unit\Planning;

use App\Planning\TimelineLayout;
use App\Planning\WeekRange;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class TimelineLayoutTest extends TestCase
{
    public function test_bar_is_positioned_in_percent_of_the_day(): void
    {
        $bar = TimelineLayout::bar('06:00', '18:00');

        $this->assertSame(25.0, $bar['left']);
        $this->assertSame(50.0, $bar['width']);
        $this->assertFalse($bar['cutEnd']);
    }

    public function test_shift_past_midnight_is_cut_at_the_end_of_the_day(): void
    {
        $bar = TimelineLayout::bar('22:00', '06:00');

        $this->assertEqualsWithDelta(91.6667, $bar['left'], 0.001);
        $this->assertEqualsWithDelta(8.3333, $bar['width'], 0.001);
        $this->assertTrue($bar['cutEnd']);
        $this->assertSame(1800, $bar['end']);
    }

    public function test_snap_and_format(): void
    {
        $this->assertSame(540, TimelineLayout::snap(547));
        $this->assertSame(0, TimelineLayout::snap(-30));
        $this->assertSame(1425, TimelineLayout::snap(5000));
        $this->assertSame('02:15', TimelineLayout::format(1440 + 135));
        $this->assertSame('00:00', TimelineLayout::format(1440));
    }

    public function test_month_grid_starts_on_the_store_week_start_day(): void
    {
        // Novembre 2026 : le 1er est un dimanche.
        $monday = WeekRange::monthGrid(CarbonImmutable::parse('2026-11-10'), 1);
        $sunday = WeekRange::monthGrid(CarbonImmutable::parse('2026-11-10'), 7);

        $this->assertSame('2026-10-26', $monday[0][0]->toDateString());
        $this->assertSame('2026-11-01', $sunday[0][0]->toDateString());
        $this->assertSame(7, count($monday[0]));
        $this->assertGreaterThanOrEqual('2026-11-30', end($monday)[6]->toDateString());
    }
}
