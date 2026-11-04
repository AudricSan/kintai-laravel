<?php

namespace Database\Factories;

use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Store>
 */
class StoreFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'code' => strtoupper(fake()->unique()->bothify('ST-####')),
            'timezone' => 'Europe/Paris',
            'week_start_day' => 1,
            'min_staff_per_day' => 0,
            'min_shift_minutes' => 120,
            'max_shift_minutes' => 480,
            'currency' => 'EUR',
            'currency_symbol_style' => 'kanji',
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
