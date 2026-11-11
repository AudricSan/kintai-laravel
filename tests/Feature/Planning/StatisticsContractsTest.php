<?php

namespace Tests\Feature\Planning;

use App\Planning\NullShiftSwapStatistics;
use App\Planning\NullTimeOffStatistics;
use Illuminate\Support\Carbon;
use Kintai\Contracts\Planning\TimeOffStatistics;
use Kintai\Contracts\Shifts\ShiftSwapStatistics;
use Tests\TestCase;

/**
 * Sans les bundles timeoff et shift-swap, le Core lie des implémentations vides des contrats de statistiques (1.7).
 */
class StatisticsContractsTest extends TestCase
{
    public function test_without_a_time_off_bundle_there_is_no_request(): void
    {
        $statistics = app(TimeOffStatistics::class);

        $this->assertInstanceOf(NullTimeOffStatistics::class, $statistics);
        $this->assertSame([], $statistics->requestsFor(1, [1, 2], Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31')));
    }

    public function test_without_a_swap_bundle_there_is_no_swap(): void
    {
        $statistics = app(ShiftSwapStatistics::class);

        $this->assertInstanceOf(NullShiftSwapStatistics::class, $statistics);
        $this->assertSame([], $statistics->swapsFor(1, Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31')));
    }
}
