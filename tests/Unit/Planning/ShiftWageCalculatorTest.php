<?php

namespace Tests\Unit\Planning;

use App\Models\Shift;
use App\Models\ShiftType;
use App\Planning\ShiftWageCalculator;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Port des tests de l'ancien ShiftWageCalculator (23 cas), plus taux personnels et ajustements.
 */
class ShiftWageCalculatorTest extends TestCase
{
    private ShiftWageCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calc = new ShiftWageCalculator;
    }

    private function type(int $id, string $name, string $start, string $end, float $rate): ShiftType
    {
        return (new ShiftType)->forceFill([
            'id' => $id, 'name' => $name, 'starts_at' => $start, 'ends_at' => $end, 'hourly_rate' => $rate,
        ]);
    }

    /**
     * @param  list<ShiftType>  $types
     * @return Collection<int, ShiftType>
     */
    private function types(array $types): Collection
    {
        return Collection::make($types)->keyBy('id');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function shift(string $start, string $end, array $attributes = []): Shift
    {
        return Shift::fromLocalTimes('2026-11-02', $start, $end, $attributes);
    }

    // --- calculate() ---

    public function test_empty_shift_types_returns_zero(): void
    {
        $result = $this->calc->calculate('09:00', '17:00', []);

        $this->assertSame(0.0, $result->total);
        $this->assertNull($result->dominantTypeId);
        $this->assertSame([], $result->slices);
    }

    public function test_shift_fully_in_one_type(): void
    {
        $result = $this->calc->calculate('09:00', '12:00', [$this->type(1, 'Matin', '08:00', '14:00', 1200.0)]);

        $this->assertSame(1, $result->dominantTypeId);
        $this->assertCount(1, $result->slices);
        $this->assertSame(180, $result->slices[0]->minutes);
        $this->assertSame(3600.0, $result->total);
    }

    public function test_shift_spanning_two_types(): void
    {
        $result = $this->calc->calculate('12:00', '16:00', [
            $this->type(1, 'Matin', '08:00', '14:00', 1000.0),
            $this->type(2, 'Après-midi', '14:00', '20:00', 1200.0),
        ]);

        $this->assertCount(2, $result->slices);
        $this->assertSame(120, $result->slices[0]->minutes);
        $this->assertSame(120, $result->slices[1]->minutes);
    }

    public function test_cross_midnight_shift(): void
    {
        $result = $this->calc->calculate('23:00', '02:00', [$this->type(1, 'Nuit', '22:00', '06:00', 1500.0)]);

        $this->assertSame(1, $result->dominantTypeId);
        $this->assertSame(180, $result->slices[0]->minutes);
        $this->assertSame(4500.0, $result->total);
    }

    public function test_shift_starting_before_midnight_ending_after(): void
    {
        $result = $this->calc->calculate('22:00', '03:00', [$this->type(1, 'Nuit', '20:00', '04:00', 2000.0)]);

        $this->assertSame(1, $result->dominantTypeId);
        $this->assertSame(300, $result->slices[0]->minutes);
    }

    public function test_net_minutes_reduces_proportionally(): void
    {
        $result = $this->calc->calculate('09:00', '17:00', [$this->type(1, 'Matin', '08:00', '17:00', 1000.0)], 450);

        $this->assertSame(450, $result->slices[0]->minutes);
        $this->assertSame(7500.0, $result->total);
        $this->assertSame(450, $result->netMinutes);
    }

    public function test_net_minutes_null_means_gross(): void
    {
        $result = $this->calc->calculate('09:00', '17:00', [$this->type(1, 'Matin', '08:00', '17:00', 1000.0)], null);

        $this->assertSame(480, $result->slices[0]->minutes);
    }

    public function test_net_minutes_equal_to_gross_is_not_reduced(): void
    {
        $result = $this->calc->calculate('09:00', '17:00', [$this->type(1, 'Matin', '08:00', '17:00', 1000.0)], 480);

        $this->assertSame(480, $result->slices[0]->minutes);
    }

    public function test_dominant_type_is_the_one_with_most_minutes(): void
    {
        $result = $this->calc->calculate('10:00', '17:00', [
            $this->type(1, 'Matin', '08:00', '13:00', 1000.0),
            $this->type(2, 'Après-midi', '13:00', '20:00', 1200.0),
        ]);

        $this->assertSame(2, $result->dominantTypeId);
        $this->assertSame(1200.0, $result->rate);
    }

    public function test_first_type_wins_a_tie(): void
    {
        $result = $this->calc->calculate('12:00', '16:00', [
            $this->type(7, 'A', '08:00', '14:00', 1000.0),
            $this->type(3, 'B', '14:00', '20:00', 1200.0),
        ]);

        $this->assertSame(7, $result->dominantTypeId);
    }

    public function test_type_outside_shift_range_is_ignored(): void
    {
        $result = $this->calc->calculate('09:00', '12:00', [
            $this->type(1, 'Nuit', '22:00', '06:00', 2000.0),
            $this->type(2, 'Matin', '08:00', '14:00', 1000.0),
        ]);

        $this->assertCount(1, $result->slices);
        $this->assertSame(2, $result->slices[0]->shiftTypeId);
    }

    public function test_zero_rate_produces_zero_amount(): void
    {
        $result = $this->calc->calculate('09:00', '12:00', [$this->type(1, 'Free', '08:00', '20:00', 0.0)]);

        $this->assertSame(0.0, $result->total);
        $this->assertSame(0.0, $result->slices[0]->amount);
    }

    public function test_slices_are_sorted_by_type_id(): void
    {
        $result = $this->calc->calculate('09:00', '18:00', [
            $this->type(3, 'C', '16:00', '20:00', 1000.0),
            $this->type(1, 'A', '08:00', '12:00', 1000.0),
            $this->type(2, 'B', '12:00', '16:00', 1000.0),
        ]);

        $this->assertSame([1, 2, 3], array_map(fn ($slice) => $slice->shiftTypeId, $result->slices));
    }

    public function test_salary_is_rounded_to_two_decimals(): void
    {
        $result = $this->calc->calculate('10:00', '11:00', [$this->type(1, 'X', '00:00', '24:00', 333.33)]);

        $this->assertSame(333.33, $result->total);
    }

    public function test_zero_duration_shift_does_not_crash(): void
    {
        $result = $this->calc->calculate('10:00', '10:00', [$this->type(1, 'Matin', '08:00', '14:00', 1000.0)]);

        $this->assertIsFloat($result->total);
    }

    public function test_type_name_is_kept_in_the_slice(): void
    {
        $result = $this->calc->calculate('09:00', '12:00', [$this->type(1, 'Matin', '08:00', '14:00', 1000.0)]);

        $this->assertSame('Matin', $result->slices[0]->name);
    }

    public function test_personal_rate_replaces_the_type_rate_per_slice(): void
    {
        $types = [$this->type(1, 'Tôt', '05:00', '08:00', 1200.0), $this->type(2, 'Jour', '08:00', '22:00', 1000.0)];

        $result = $this->calc->calculate('07:00', '18:00', $types, null, [2 => 1500.0]);

        $this->assertSame(1200.0, $result->slices[0]->rate);
        $this->assertSame(1500.0, $result->slices[1]->rate);
        $this->assertSame(1500.0, $result->rate);
    }

    // --- costOf() ---

    public function test_cost_of_uses_shift_type_rate_by_default(): void
    {
        $shift = $this->shift('08:00', '16:00', ['pause_minutes' => 60, 'shift_type_id' => 1]);

        $result = $this->calc->costOf($shift, $this->types([$this->type(1, 'Matin', '08:00', '16:00', 1000.0)]));

        $this->assertSame(1000.0, $result->rate);
        $this->assertSame(420, $result->netMinutes);
        $this->assertSame(7000.0, $result->total);
    }

    public function test_cost_of_prefers_personal_rate_over_type_rate(): void
    {
        $shift = $this->shift('08:00', '09:00', ['shift_type_id' => 1]);

        $result = $this->calc->costOf($shift, $this->types([$this->type(1, 'Matin', '08:00', '16:00', 1000.0)]), [1 => 1500.0]);

        $this->assertSame(1500.0, $result->rate);
        $this->assertSame(1500.0, $result->total);
    }

    public function test_cost_of_hourly_rate_override_wins_over_everything(): void
    {
        $shift = $this->shift('08:00', '09:00', ['shift_type_id' => 1, 'hourly_rate_override' => 2000.0]);

        $result = $this->calc->costOf($shift, $this->types([$this->type(1, 'Matin', '08:00', '16:00', 1000.0)]), [1 => 1500.0]);

        $this->assertSame(2000.0, $result->rate);
        $this->assertSame(2000.0, $result->total);
        $this->assertSame('Matin', $result->slices[0]->name);
    }

    public function test_cost_of_net_minutes_override_wins_over_duration_minus_pause(): void
    {
        $shift = $this->shift('08:00', '16:00', ['pause_minutes' => 60, 'net_minutes_override' => 300, 'shift_type_id' => 1]);

        $result = $this->calc->costOf($shift, $this->types([$this->type(1, 'Matin', '08:00', '16:00', 1000.0)]));

        $this->assertSame(300, $result->netMinutes);
        $this->assertSame(5000.0, $result->total);
    }

    public function test_cost_of_without_shift_type_has_a_zero_rate(): void
    {
        $result = $this->calc->costOf($this->shift('08:00', '16:00'), new Collection);

        $this->assertSame(0.0, $result->rate);
        $this->assertSame(0.0, $result->total);
        $this->assertSame(480, $result->netMinutes);
    }

    public function test_cost_of_splits_across_multiple_shift_types(): void
    {
        $types = $this->types([$this->type(1, 'Tôt', '05:00', '08:00', 1200.0), $this->type(2, 'Jour', '08:00', '22:00', 1000.0)]);

        $result = $this->calc->costOf($this->shift('07:00', '18:00', ['pause_minutes' => 0]), $types);

        $this->assertCount(2, $result->slices);
        $this->assertSame(60, $result->slices[0]->minutes);
        $this->assertSame(600, $result->slices[1]->minutes);
        $this->assertSame(11200.0, $result->total);
        $this->assertSame(660, $result->netMinutes);
        $this->assertSame(1000.0, $result->rate);
    }

    public function test_cost_of_applies_personal_rate_per_slice_only(): void
    {
        $types = $this->types([$this->type(1, 'Tôt', '05:00', '08:00', 1200.0), $this->type(2, 'Jour', '08:00', '22:00', 1000.0)]);

        $result = $this->calc->costOf($this->shift('07:00', '18:00', ['pause_minutes' => 0]), $types, [2 => 1500.0]);

        $this->assertSame(1200.0, $result->slices[0]->rate);
        $this->assertSame(1500.0, $result->slices[1]->rate);
        $this->assertSame(16200.0, $result->total);
    }

    public function test_cost_of_has_a_single_slice_with_a_flat_rate(): void
    {
        $types = $this->types([$this->type(1, 'Tôt', '05:00', '08:00', 1200.0), $this->type(2, 'Jour', '08:00', '22:00', 1000.0)]);
        $shift = $this->shift('07:00', '18:00', ['pause_minutes' => 0, 'shift_type_id' => 1, 'hourly_rate_override' => 2000.0]);

        $result = $this->calc->costOf($shift, $types);

        $this->assertCount(1, $result->slices);
        $this->assertSame(660, $result->slices[0]->minutes);
        $this->assertSame(2000.0, $result->slices[0]->rate);
        $this->assertSame(22000.0, $result->total);
    }

    public function test_cost_of_reads_local_times_of_the_instance_timezone(): void
    {
        config(['kintai.timezone' => 'Asia/Tokyo']);
        $types = $this->types([$this->type(1, 'Matin', '08:00', '14:00', 1000.0)]);

        $result = $this->calc->costOf($this->shift('09:00', '12:00', ['pause_minutes' => 0]), $types);

        $this->assertSame(180, $result->slices[0]->minutes);
        $this->assertSame(3000.0, $result->total);
    }
}
