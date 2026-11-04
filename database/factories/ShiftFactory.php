<?php

namespace Database\Factories;

use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Shift>
 */
class ShiftFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $day = Carbon::instance(fake()->dateTimeBetween('-1 month', '+1 month'))->startOfDay();

        return [
            'store_id' => Store::factory(),
            'user_id' => User::factory(),
            'shift_date' => $day->toDateString(),
            'starts_at' => Shift::localTime($day->toDateString(), '09:00'),
            'ends_at' => Shift::localTime($day->toDateString(), '17:00'),
            'pause_minutes' => 60,
        ];
    }

    /**
     * Shift ouvert : aucun employé assigné.
     */
    public function open(): static
    {
        return $this->state(fn (array $attributes) => ['user_id' => null]);
    }

    /**
     * Shift de nuit (heures locales) : 22:00 le jour de rattachement jusqu'à 06:00 le lendemain.
     */
    public function crossingMidnight(): static
    {
        return $this->state(function (array $attributes) {
            $date = Carbon::parse($attributes['shift_date'])->toDateString();

            return [
                'starts_at' => Shift::localTime($date, '22:00'),
                'ends_at' => Shift::localTime(Carbon::parse($date)->addDay()->toDateString(), '06:00'),
            ];
        });
    }

    /**
     * Shift avec taux horaire et minutes nettes imposés.
     */
    public function withOverrides(float $hourlyRate = 15.0, int $netMinutes = 420): static
    {
        return $this->state(fn (array $attributes) => [
            'hourly_rate_override' => $hourlyRate,
            'net_minutes_override' => $netMinutes,
        ]);
    }
}
