<?php

declare(strict_types=1);

namespace Tests\Bundles\Timeclock;

use kintai\Bundles\Installed\Timeclock\Providers\TimeclockAttendanceProvider;
use Tests\Support\TestContainer;
use PHPUnit\Framework\TestCase;
use Tests\Support\RealTable;

/** Fournisseur de pointages du bundle (contrat 1.11) : mêmes lignes que la lecture historique de la table par le Core. */
final class TimeclockAttendanceProviderTest extends TestCase
{
    use RealTable;

    private function realTable(): array
    {
        return ['timeclocks', 'timeclock'];
    }

    private function provider(): TimeclockAttendanceProvider
    {
        $rows = [
            ['id' => 1, 'user_id' => 4, 'store_id' => 1, 'shift_date' => '2026-10-13', 'clock_in_time' => '2026-10-13 09:00:00', 'clock_out_time' => '2026-10-13 17:00:00'],
            ['id' => 2, 'user_id' => 4, 'store_id' => 1, 'shift_date' => '2026-10-14', 'clock_in_time' => '2026-10-14 08:58:00', 'clock_out_time' => null],
            ['id' => 3, 'user_id' => 5, 'store_id' => 2, 'shift_date' => '2026-10-14', 'clock_in_time' => '2026-10-14 10:00:00', 'clock_out_time' => ''],
        ];
        $this->resetRows();
        $this->seedRows($rows);
        $container = new TestContainer();
        return new TimeclockAttendanceProvider($container);
    }

    public function testFiltersAndShapesLikeTheLegacyRows(): void
    {
        $provider = $this->provider();

        $this->assertSame([1, 2, 3], array_map(fn($a) => $a->id, $provider->attendance(null, null)), 'pointage sans arrivée ignoré');
        $this->assertSame([2], array_map(fn($a) => $a->id, $provider->attendance(null, [1], '2026-10-14', '2026-10-14')));
        $this->assertSame([1, 2], array_map(fn($a) => $a->id, $provider->attendance([4], null)));
        $this->assertSame(
            ['id' => 3, 'user_id' => 5, 'store_id' => 2, 'shift_date' => '2026-10-14', 'clock_in_time' => '2026-10-14 10:00:00', 'clock_out_time' => null],
            $provider->attendance([5], null)[0]->toArray(),
            'départ vide = pointage en cours'
        );
    }
}
