<?php

declare(strict_types=1);

namespace Tests\Bundles\TimeOff;

use kintai\Bundles\Installed\TimeOff\Providers\TimeoffAbsenceProvider;
use Tests\Support\TestContainer;
use PHPUnit\Framework\TestCase;
use Tests\Support\RealTable;

/** Fournisseur d'absences du bundle (contrat 1.10) : mêmes lignes que la lecture historique de la table par le Core. */
final class TimeoffAbsenceProviderTest extends TestCase
{
    use RealTable;

    private function realTable(): array
    {
        return ['timeoff_requests', 'timeoff'];
    }

    private function provider(): TimeoffAbsenceProvider
    {
        $rows = [
            ['id' => 1, 'user_id' => 4, 'store_id' => 1, 'start_date' => '2026-10-01', 'end_date' => '2026-10-02', 'type' => 'vacation', 'status' => 'approved'],
            ['id' => 2, 'user_id' => 5, 'store_id' => 2, 'start_date' => '2026-10-03', 'end_date' => '2026-10-03', 'type' => 'sick', 'status' => 'pending'],
            ['id' => 3, 'user_id' => 4, 'store_id' => 2, 'start_date' => '2026-10-09', 'end_date' => '2026-10-08', 'type' => 'sick', 'status' => 'pending'],
        ];
        $this->resetRows();
        $this->seedRows($rows);
        $container = new TestContainer();
        return new TimeoffAbsenceProvider($container);
    }

    public function testFiltersByUserOrStoreAndKeepsEveryStatus(): void
    {
        $byUser = array_map(fn($a) => $a->toArray(), $this->provider()->absences([4], null));
        $this->assertSame(
            [['id' => 1, 'user_id' => 4, 'store_id' => 1, 'start_date' => '2026-10-01', 'end_date' => '2026-10-02', 'type' => 'vacation', 'status' => 'approved']],
            $byUser,
            'la ligne aux dates incohérentes est ignorée'
        );
        $this->assertSame([2], array_map(fn($a) => $a->id, $this->provider()->absences(null, [2])));
        $this->assertSame([1, 2], array_map(fn($a) => $a->id, $this->provider()->absences(null, null)));
        $this->assertSame([], $this->provider()->absences([4], [2]));
    }
}
