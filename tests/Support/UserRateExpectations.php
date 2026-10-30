<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\UserShiftTypeRate;
use Illuminate\Support\Facades\DB;

/**
 * Taux horaires individuels dans la table user_shift_type_rates pour les tests : base neuve avant chaque test, taux
 * préparés avec leurs dépendances (employé, type de shift), écritures vérifiées à la fin du test.
 */
trait UserRateExpectations
{
    private int $ratesAtStart = 0;
    private bool $expectNoRateWrite = false;
    /** @var list<array{int, int, float}> */
    private array $expectedRates = [];
    /** @var list<int> */
    private array $keptRates = [];
    /** @var list<int> */
    private array $removedRates = [];

    #[\PHPUnit\Framework\Attributes\Before]
    protected function ratesDatabase(): void
    {
        kintai_test_database();
        kintai_test_migrate();
    }

    /** Taux personnel d'un employé pour un type de shift ; crée l'employé et le type s'ils n'existent pas. */
    private function seedRate(int $userId, int $shiftTypeId, float $rate, ?int $id = null): int
    {
        kintai_test_user($userId);
        DB::table('shift_types')->insertOrIgnore(['id' => $shiftTypeId, 'name' => 'Type ' . $shiftTypeId, 'code' => 'T' . $shiftTypeId, 'start_time' => '09:00', 'end_time' => '17:00']);
        $row = UserShiftTypeRate::create(array_filter(['id' => $id, 'user_id' => $userId, 'shift_type_id' => $shiftTypeId, 'hourly_rate' => $rate], static fn($v) => $v !== null));
        $this->ratesAtStart = UserShiftTypeRate::count();

        return (int) $row->id;
    }

    /** Employé et type de shift visés par un taux que le code testé va écrire. */
    private function prepareRateTarget(int $userId, int $shiftTypeId): void
    {
        kintai_test_user($userId);
        DB::table('shift_types')->insertOrIgnore(['id' => $shiftTypeId, 'name' => 'Type ' . $shiftTypeId, 'code' => 'T' . $shiftTypeId, 'start_time' => '09:00', 'end_time' => '17:00']);
    }

    private function expectNoRateWrite(): void
    {
        $this->ratesAtStart = UserShiftTypeRate::count();
        $this->expectNoRateWrite = true;
    }

    private function expectRate(int $userId, int $shiftTypeId, float $rate): void
    {
        $this->expectedRates[] = [$userId, $shiftTypeId, $rate];
    }

    private function expectRateKept(int $id): void
    {
        $this->keptRates[] = $id;
    }

    private function expectRateRemoved(int $id): void
    {
        $this->removedRates[] = $id;
    }

    protected function assertPostConditions(): void
    {
        if ($this->expectNoRateWrite) {
            $this->assertSame($this->ratesAtStart, UserShiftTypeRate::count(), 'aucun taux écrit');
        }
        foreach ($this->expectedRates as [$userId, $shiftTypeId, $rate]) {
            $row = UserShiftTypeRate::findRate($userId, $shiftTypeId);
            $this->assertNotNull($row, "taux enregistré ($userId, $shiftTypeId)");
            $this->assertEquals($rate, (float) $row['hourly_rate']);
        }
        foreach ($this->keptRates as $id) {
            $this->assertNotNull(UserShiftTypeRate::findById($id), "taux $id conservé");
        }
        foreach ($this->removedRates as $id) {
            $this->assertNull(UserShiftTypeRate::findById($id), "taux $id supprimé");
        }
    }
}
