<?php

declare(strict_types=1);

namespace Tests\Bundles\Timeclock\Services;

use kintai\Bundles\Installed\Timeclock\Services\TimeclockWidget;
use App\Models\User;
use PHPUnit\Framework\TestCase;
use Tests\Support\RealTable;

/**
 * Widgets de pointage des tableaux de bord (contrat 1.4) : mêmes pointages que ceux que le Core affichait.
 */
final class TimeclockWidgetTest extends TestCase
{
    use RealTable;

    private function realTable(): array
    {
        return ['timeclocks', 'timeclock'];
    }

    public function testManagerSeesTodaysOpenClockInsOfItsStores(): void
    {
        $this->resetRows();
        $this->seedRows([
            ['id' => 1, 'store_id' => 1, 'user_id' => 5, 'shift_date' => '2026-10-14', 'clock_in_time' => '2026-10-14 09:00:00', 'clock_out_time' => null],
            ['id' => 2, 'store_id' => 1, 'user_id' => 6, 'shift_date' => '2026-10-14', 'clock_in_time' => '2026-10-14 08:00:00', 'clock_out_time' => '2026-10-14 12:00:00'],
            ['id' => 3, 'store_id' => 2, 'user_id' => 6, 'shift_date' => '2026-10-14', 'clock_in_time' => '2026-10-14 10:00:00', 'clock_out_time' => null],
            ['id' => 4, 'store_id' => 1, 'user_id' => 7, 'shift_date' => '2026-10-13', 'clock_in_time' => '2026-10-13 22:00:00', 'clock_out_time' => null],
            ['id' => 5, 'store_id' => 2, 'user_id' => 8, 'shift_date' => '2026-10-14', 'clock_in_time' => '2026-10-14 07:00:00', 'clock_out_time' => null],
        ]);
        kintai_test_users(findAll: fn() => [['id' => 5, 'display_name' => 'Sato']]);
        $active = (new TimeclockWidget())->activeNow([1], [1 => 'Tokorozawa'], '2026-10-14');

        $this->assertSame([1], array_column($active, 'id'));
        $this->assertSame([5, 1, 3], array_column((new TimeclockWidget())->activeNow([1, 2], [], '2026-10-14'), 'id'), 'plus ancienne entrée d\'abord');
        $this->assertSame(['Sato', 'Tokorozawa'], [$active[0]['user_name'], $active[0]['store_name']]);
    }

    public function testEmployeeSeesItsOpenClockIn(): void
    {
        $this->resetRows();
        $this->seedRows([['id' => 1, 'user_id' => 5, 'clock_out_time' => null]]);
        $widget = new TimeclockWidget();

        $this->assertSame(1, $widget->activeForUser(5)['id']);
        $this->assertNull($widget->activeForUser(6));
    }
}
