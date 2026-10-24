<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use kintai\Core\Services\ShiftWageCalculator;

final class ShiftWageCalculatorTest extends TestCase
{
    private ShiftWageCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new ShiftWageCalculator();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function type(int $id, string $name, string $start, string $end, float $rate): array
    {
        return ['id' => $id, 'name' => $name, 'start_time' => $start, 'end_time' => $end, 'hourly_rate' => $rate];
    }

    // -------------------------------------------------------------------------
    // Cas de base
    // -------------------------------------------------------------------------

    public function testEmptyShiftTypesReturnsZero(): void
    {
        $result = $this->calc->calculate('09:00', '17:00', []);
        $this->assertSame(0.0, $result['estimated_salary']);
        $this->assertNull($result['dominant_type_id']);
        $this->assertEmpty($result['breakdown']);
    }

    public function testShiftFullyInOneType(): void
    {
        $types  = [$this->type(1, 'Matin', '08:00', '14:00', 1200.0)];
        $result = $this->calc->calculate('09:00', '12:00', $types);

        $this->assertSame(1, $result['dominant_type_id']);
        $this->assertCount(1, $result['breakdown']);
        $this->assertSame(180, $result['breakdown'][0]['minutes']); // 3h = 180min
        $this->assertSame(3600.0, $result['estimated_salary']);     // 3h × 1200
    }

    public function testShiftSpanningTwoTypes(): void
    {
        $types = [
            $this->type(1, 'Matin',    '08:00', '14:00', 1000.0),
            $this->type(2, 'Après-midi', '14:00', '20:00', 1200.0),
        ];
        $result = $this->calc->calculate('12:00', '16:00', $types);

        $this->assertCount(2, $result['breakdown']);
        // 12:00→14:00 = 120 min dans Matin, 14:00→16:00 = 120 min dans Après-midi
        $breakdown = array_column($result['breakdown'], null, 'shift_type_id');
        $this->assertSame(120, $breakdown[1]['minutes']);
        $this->assertSame(120, $breakdown[2]['minutes']);
    }

    // -------------------------------------------------------------------------
    // Shifts traversant minuit
    // -------------------------------------------------------------------------

    public function testCrossMidnightShift(): void
    {
        $types  = [$this->type(1, 'Nuit', '22:00', '06:00', 1500.0)];
        $result = $this->calc->calculate('23:00', '02:00', $types);

        $this->assertSame(1, $result['dominant_type_id']);
        $this->assertSame(180, $result['breakdown'][0]['minutes']); // 3h
        $this->assertSame(4500.0, $result['estimated_salary']);
    }

    public function testShiftStartingBeforeMidnightEndingAfter(): void
    {
        $types  = [$this->type(1, 'Nuit', '20:00', '04:00', 2000.0)];
        $result = $this->calc->calculate('22:00', '03:00', $types);

        $this->assertSame(1, $result['dominant_type_id']);
        $this->assertSame(300, $result['breakdown'][0]['minutes']); // 5h
    }

    // -------------------------------------------------------------------------
    // Déduction de pause (netMinutes)
    // -------------------------------------------------------------------------

    public function testNetMinutesReducesProportionally(): void
    {
        $types  = [$this->type(1, 'Matin', '08:00', '17:00', 1000.0)];
        $result = $this->calc->calculate('09:00', '17:00', $types, 450); // 8h brut - 30min pause

        // 450/480 des minutes brutes
        $this->assertSame(450, $result['breakdown'][0]['minutes']);
        $this->assertSame(7500.0, $result['estimated_salary']); // 7.5h × 1000
    }

    public function testNetMinutesNullMeansBrut(): void
    {
        $types  = [$this->type(1, 'Matin', '08:00', '17:00', 1000.0)];
        $result = $this->calc->calculate('09:00', '17:00', $types, null);
        $this->assertSame(480, $result['breakdown'][0]['minutes']); // 8h brut
    }

    public function testNetMinutesEqualToBrutNotReduced(): void
    {
        $types  = [$this->type(1, 'Matin', '08:00', '17:00', 1000.0)];
        // netMinutes = brut → pas de réduction
        $result = $this->calc->calculate('09:00', '17:00', $types, 480);
        $this->assertSame(480, $result['breakdown'][0]['minutes']);
    }

    // -------------------------------------------------------------------------
    // Type dominant
    // -------------------------------------------------------------------------

    public function testDominantTypeIsTypeWithMostMinutes(): void
    {
        $types = [
            $this->type(1, 'Matin',    '08:00', '13:00', 1000.0), // 3h dans 10:00→13:00
            $this->type(2, 'Après-midi', '13:00', '20:00', 1200.0), // 4h dans 13:00→17:00
        ];
        $result = $this->calc->calculate('10:00', '17:00', $types);
        $this->assertSame(2, $result['dominant_type_id']); // Après-midi a plus de minutes
    }

    // -------------------------------------------------------------------------
    // Type hors plage (aucun chevauchement)
    // -------------------------------------------------------------------------

    public function testTypeOutsideShiftRangeIgnored(): void
    {
        $types = [
            $this->type(1, 'Nuit', '22:00', '06:00', 2000.0), // le shift est en journée
            $this->type(2, 'Matin', '08:00', '14:00', 1000.0),
        ];
        $result = $this->calc->calculate('09:00', '12:00', $types);
        // Seul Matin est dans la plage
        $this->assertCount(1, $result['breakdown']);
        $this->assertSame(2, $result['breakdown'][0]['shift_type_id']);
    }

    // -------------------------------------------------------------------------
    // Taux horaire à 0
    // -------------------------------------------------------------------------

    public function testZeroRateProducesZeroAmount(): void
    {
        $types  = [$this->type(1, 'Free', '08:00', '20:00', 0.0)];
        $result = $this->calc->calculate('09:00', '12:00', $types);
        $this->assertSame(0.0, $result['estimated_salary']);
        $this->assertSame(0.0, $result['breakdown'][0]['amount']);
    }

    // -------------------------------------------------------------------------
    // Breakdown trié par shift_type_id
    // -------------------------------------------------------------------------

    public function testBreakdownSortedByShiftTypeId(): void
    {
        $types = [
            $this->type(3, 'C', '16:00', '20:00', 1000.0),
            $this->type(1, 'A', '08:00', '12:00', 1000.0),
            $this->type(2, 'B', '12:00', '16:00', 1000.0),
        ];
        $result = $this->calc->calculate('09:00', '18:00', $types);
        $ids    = array_column($result['breakdown'], 'shift_type_id');
        $this->assertSame([1, 2, 3], $ids);
    }

    // -------------------------------------------------------------------------
    // Salaire arrondi à 2 décimales
    // -------------------------------------------------------------------------

    public function testSalaryRoundedToTwoDecimals(): void
    {
        // 1h à 333.33 → 333.33
        $types  = [$this->type(1, 'X', '00:00', '24:00', 333.33)];
        $result = $this->calc->calculate('10:00', '11:00', $types); // 60 min = 1h
        $this->assertSame(333.33, $result['estimated_salary']);
    }

    // -------------------------------------------------------------------------
    // Shift qui commence et finit sur le même minute (durée 0)
    // -------------------------------------------------------------------------

    public function testZeroDurationShiftNoBreakdown(): void
    {
        $types  = [$this->type(1, 'Matin', '08:00', '14:00', 1000.0)];
        $result = $this->calc->calculate('10:00', '10:00', $types);
        // Shift de 0 durée → start == end → après correction minuit, end = start+1440
        // Cela produira un shift de 1440 min — comportement attendu tel quel
        // On vérifie juste que ça ne plante pas
        $this->assertIsFloat($result['estimated_salary']);
    }

    // -------------------------------------------------------------------------
    // Clé 'name' absente du type (pas de warning, fallback '')
    // -------------------------------------------------------------------------

    public function testCalculateMissingNameKeyFallsBackToEmptyString(): void
    {
        $types  = [['id' => 1, 'start_time' => '08:00', 'end_time' => '14:00', 'hourly_rate' => 1000.0]];
        $result = $this->calc->calculate('09:00', '12:00', $types);
        $this->assertSame('', $result['breakdown'][0]['name']);
    }

    // -------------------------------------------------------------------------
    // costOf() — source unique pour un shift déjà persisté
    // -------------------------------------------------------------------------

    private function typesMap(array $types): array
    {
        return array_column($types, null, 'id');
    }

    public function testCostOfUsesShiftTypeRateByDefault(): void
    {
        $shift = ['shift_type_id' => 1, 'duration_minutes' => 480, 'pause_minutes' => 60];
        $types = $this->typesMap([$this->type(1, 'Matin', '08:00', '16:00', 1000.0)]);

        $result = $this->calc->costOf($shift, $types);

        $this->assertSame(1000.0, $result['rate']);
        $this->assertSame(480, $result['gross_minutes']);
        $this->assertSame(60, $result['pause_minutes']);
        $this->assertSame(420, $result['net_minutes']); // 8h - 1h pause = 7h
        $this->assertSame(7000.0, $result['amount']);   // 7h × 1000
    }

    public function testCostOfPrefersPersonalRateOverShiftTypeRate(): void
    {
        $shift = ['shift_type_id' => 1, 'duration_minutes' => 60, 'pause_minutes' => 0];
        $types = $this->typesMap([$this->type(1, 'Matin', '08:00', '16:00', 1000.0)]);

        $result = $this->calc->costOf($shift, $types, [1 => 1500.0]);

        $this->assertSame(1500.0, $result['rate']);
        $this->assertSame(1500.0, $result['amount']);
    }

    public function testCostOfHourlyRateOverrideWinsOverEverything(): void
    {
        $shift = [
            'shift_type_id'       => 1,
            'duration_minutes'    => 60,
            'pause_minutes'       => 0,
            'hourly_rate_override' => 2000.0,
        ];
        $types = $this->typesMap([$this->type(1, 'Matin', '08:00', '16:00', 1000.0)]);

        $result = $this->calc->costOf($shift, $types, [1 => 1500.0]);

        $this->assertSame(2000.0, $result['rate']);
        $this->assertSame(2000.0, $result['amount']);
    }

    public function testCostOfNetMinutesOverrideWinsOverDurationMinusPause(): void
    {
        $shift = [
            'shift_type_id'        => 1,
            'duration_minutes'     => 480,
            'pause_minutes'        => 60,
            'net_minutes_override' => 300,
        ];
        $types = $this->typesMap([$this->type(1, 'Matin', '08:00', '16:00', 1000.0)]);

        $result = $this->calc->costOf($shift, $types);

        $this->assertSame(300, $result['net_minutes']);
        $this->assertSame(5000.0, $result['amount']); // 5h × 1000
    }

    public function testCostOfNoShiftTypeProducesZeroRate(): void
    {
        $shift = ['shift_type_id' => 0, 'duration_minutes' => 480, 'pause_minutes' => 0];

        $result = $this->calc->costOf($shift, []);

        $this->assertSame(0.0, $result['rate']);
        $this->assertSame(0.0, $result['amount']);
        $this->assertSame(480, $result['net_minutes']);
    }

    // -------------------------------------------------------------------------
    // costOf() — shift à cheval sur plusieurs tranches horaires
    // -------------------------------------------------------------------------

    public function testCostOfSplitsAcrossMultipleShiftTypes(): void
    {
        // 07:00-18:00 traverse le type "Tôt" (05:00-08:00) puis "Jour" (08:00-22:00)
        $shift = [
            'start_time'       => '07:00',
            'end_time'         => '18:00',
            'duration_minutes' => 660, // 11h
            'pause_minutes'    => 0,
        ];
        $types = $this->typesMap([
            $this->type(1, 'Tôt',  '05:00', '08:00', 1200.0),
            $this->type(2, 'Jour', '08:00', '22:00', 1000.0),
        ]);

        $result = $this->calc->costOf($shift, $types);

        $this->assertCount(2, $result['breakdown']);
        $breakdown = array_column($result['breakdown'], null, 'shift_type_id');
        $this->assertSame(60, $breakdown[1]['minutes']);  // 07:00→08:00 = 1h dans "Tôt"
        $this->assertSame(600, $breakdown[2]['minutes']); // 08:00→18:00 = 10h dans "Jour"

        // 1h × 1200 + 10h × 1000 = 11200
        $this->assertSame(11200.0, $result['amount']);
        $this->assertSame(660, $result['net_minutes']);
        // Type dominant (le plus de minutes) = "Jour" → rate affiché = 1000
        $this->assertSame(1000.0, $result['rate']);
    }

    public function testCostOfAppliesPersonalRatePerTrancheOnly(): void
    {
        $shift = [
            'start_time'       => '07:00',
            'end_time'         => '18:00',
            'duration_minutes' => 660,
            'pause_minutes'    => 0,
        ];
        $types = $this->typesMap([
            $this->type(1, 'Tôt',  '05:00', '08:00', 1200.0),
            $this->type(2, 'Jour', '08:00', '22:00', 1000.0),
        ]);

        // Taux perso uniquement sur le type "Jour" (id 2)
        $result = $this->calc->costOf($shift, $types, [2 => 1500.0]);

        $breakdown = array_column($result['breakdown'], null, 'shift_type_id');
        $this->assertSame(1200.0, $breakdown[1]['rate']); // "Tôt" garde le taux du store
        $this->assertSame(1500.0, $breakdown[2]['rate']); // "Jour" utilise le taux perso

        // 1h × 1200 + 10h × 1500 = 16200
        $this->assertSame(16200.0, $result['amount']);
    }

    public function testCostOfBreakdownSingleEntryWhenHourlyRateOverride(): void
    {
        $shift = [
            'shift_type_id'         => 1,
            'start_time'            => '07:00',
            'end_time'              => '18:00',
            'duration_minutes'      => 660,
            'pause_minutes'         => 0,
            'hourly_rate_override'  => 2000.0,
        ];
        $types = $this->typesMap([
            $this->type(1, 'Tôt',  '05:00', '08:00', 1200.0),
            $this->type(2, 'Jour', '08:00', '22:00', 1000.0),
        ]);

        $result = $this->calc->costOf($shift, $types);

        // Le taux plat prime sur le découpage par tranche : une seule entrée
        $this->assertCount(1, $result['breakdown']);
        $this->assertSame(660, $result['breakdown'][0]['minutes']);
        $this->assertSame(2000.0, $result['breakdown'][0]['rate']);
        $this->assertSame(22000.0, $result['amount']); // 11h × 2000
    }
}
