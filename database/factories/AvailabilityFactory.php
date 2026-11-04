<?php

namespace Database\Factories;

use App\Models\Availability;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Availability>
 */
class AvailabilityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'store_id' => Store::factory(),
            'day_of_week' => fake()->numberBetween(1, 7),
            'starts_at' => '09:00',
            'ends_at' => '17:00',
            'is_available' => true,
        ];
    }

    public function unavailable(): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => null,
            'ends_at' => null,
            'is_available' => false,
        ]);
    }
}
